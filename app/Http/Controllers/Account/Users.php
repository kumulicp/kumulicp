<?php

namespace App\Http\Controllers\Account;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Enums\AccessType;
use App\Events\Users\DeletingUser;
use App\Events\Users\UserCreated;
use App\Events\Users\UserDeleted;
use App\Events\Users\UserUpdated;
use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Notifications\ResetPassword;
use App\OrgDomain;
use App\Mailbox;
use App\Rules\AccountEmailChecks;
use App\Rules\EmailLocalPart;
use App\Rules\EmailAddressExists;
use App\Rules\MainContact;
use App\Rules\UserNotExists;
use App\Services\AdditionalStorageService;
use App\Services\Email\MailboxException;
use App\Services\Email\MailboxService;
use App\SuborgUser;
use App\Support\Email\UserEmails;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

class Users extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        $plan = Subscription::base();
        $access_types = [
            'standard' => $plan->accessTypeLabel(AccessType::STANDARD),
            'basic' => $plan->accessTypeLabel(AccessType::BASIC),
            'minimal' => $plan->accessTypeLabel(AccessType::MINIMAL),
            'none' => __('labels.deactivated'),
        ];

        $users = AccountManager::users()->collect();
        $emails = UserEmails::lookup(
            $users->map(fn ($user) => $user->attribute('username'))->all(),
            $users->mapWithKeys(fn ($user) => [$user->attribute('username') => $user->attribute('email')])->all(),
        );

        return inertia('Organization/Users/UsersList', [
            'users' => $users->map(function ($user, $value) use ($emails) {
                return [
                    'id' => $user->attribute('username'),
                    'name' => $user->attribute('name'),
                    'personal_email' => $emails[$user->attribute('username')]['personal_email'],
                    'org_email' => $emails[$user->attribute('username')]['org_email'],
                    'access_type' => $user->userAccessType()?->label(),
                    'links' => [
                        'edit' => route('users.edit', $user->attribute('username')),
                        'permissions' => route('users.permissions.edit', $user->attribute('username')),
                        'show' => route('users.show', $user->attribute('username')),
                        'reset_password' => '/users/'.$user->attribute('username').'/reset_password',
                    ],
                    'can' => [
                        'delete' => Gate::allows('delete-user', $user),
                    ],
                ];
            }),
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                ],
            ],
            'can' => [
                'add_user' => Gate::allows('add-user'),
                'active' => Gate::allows('active'),
            ],
        ]);
    }

    public function show($userid)
    {
        $organization = auth()->user()->organization;
        $user = AccountManager::users()->find($userid);
        Gate::authorize('view-user', [$user]);

        if (! $user) {
            // return to user list if user doesn't exist
            return to_route('users');
        }

        $active_apps = Organization::apps();
        $apps = [];

        foreach ($active_apps as $app_instance) {
            $categories = [];
            $category_id = 0;
            $app_access = false;
            if (Auth::user()->can('add-app-user', $app_instance) || $user->hasAccessToApps([$app_instance->name])) {
                if (count($app_instance->version->roles()) > 0) {
                    foreach ($app_instance->version->group_categories() as $category) {
                        $active_role = 'None';
                        foreach ($app_instance->version->groupsFromCategory($category->category) as $role) {
                            if ($user->hasAppRole($app_instance, $role)) {
                                $active_role = $role->name;
                                $app_access = true;
                            }
                        }

                        $categories[] = [
                            'id' => $category_id,
                            'name' => $category->category,
                            'access' => $active_role,
                        ];
                        $category_id++;
                    }

                    if ($app_access) {
                        $apps[] = [
                            'id' => $app_instance->id,
                            'name' => $app_instance->label,
                            'categories' => $categories,
                        ];
                    }
                }
            }
        }

        $user_storage = [];
        foreach ($user->allUserApps() as $app) {
            if (isset($app->user) && ! isset($app->error)) {
                $user_storage[] = [
                    'app' => $app->info->application->name,
                    'error' => isset($app->error),
                    'quota_used' => $app->user->quota->used,
                    'quota_total' => $user->appStorage($app->info),
                    'unit' => 'GB',
                ];
            } elseif (isset($app->user)) {
                $user_storage[] = [
                    'app' => $app->info->application->name,
                    'error' => isset($app->error),
                    'quota_used' => 0,
                    'quota_total' => $user->appStorage($app->info),
                    'unit' => 'GB',
                ];
            }
        }

        $user_emails = UserEmails::for($user);

        return inertia('Organization/Users/UserView', [
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'phone_number' => $user->attribute('phone_number'),
                'personal_email' => $user_emails['personal_email'],
                'org_email' => $user_emails['org_email'],
                'type' => Subscription::base()->accessTypeName($user->userAccessType()),
                'storage' => $user_storage,
                'url' => [
                    'show' => '/users/'.$user->attribute('username'),
                    'edit' => '/users/'.$user->attribute('username').'/edit',
                    'permissions' => '/users/'.$user->attribute('username').'/permissions',
                ],
                'permissions' => $apps,
                'groups' => $user->listGroups(),
            ],
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                    'url' => '/users',
                ],
                [
                    'label' => $user->attribute('name'),
                ],
            ],
        ]);
    }

    public function retrieve(Request $request)
    {
        /* Validate */
        $validatedData = $request->validate([
            'username' => 'required|alpha_num|max:100',
        ]);

        $user = AccountManager::users()->find($request->username);

        if (! $user) {
            return response()->json([], 404);
        }

        Gate::authorize('view-user', $user);

        $info = [
            'username' => $user->attribute('username'),
            'first_name' => $user->attribute('first_name'),
            'last_name' => $user->attribute('last_name'),
            'display_name' => $user->attribute('name'),
            'email' => $user->attribute('email'),
            'phone' => $user->attribute('phone_number'),
        ];

        return response()->json($info);
    }

    public function store(Request $request)
    {
        Gate::authorize('add-user');

        $organization = auth()->user()->organization;

        /* Validate */
        $validatedData = $request->validate([
            'username' => ['required', 'alpha_num', 'lowercase', new UserNotExists],
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'personal_email' => ['email:rfc,filter', 'required', new AccountEmailChecks, new EmailAddressExists],
            'account_email' => '',
            'phone_number' => '',
        ]);

        $lock = Cache::lock('add-user-'.$organization->id, 30);
        $lock->block(10);

        try {
            // Re-check limit inside the lock to prevent TOCTOU race
            if (! Gate::allows('add-user')) {
                return back()->withErrors(['limit' => __('organization.user.denied.add')]);
            }

            $input['username'] = $request->username;
            $input['first_name'] = $request->first_name;
            $input['last_name'] = $request->last_name;
            $input['name'] = $request->first_name.' '.$request->last_name;
            $input['email'] = $request->personal_email;
            $input['account_email'] = $request->organization_email;
            $input['password'] = Str::password(20, true, true, false, false);
            $input['phone_number'] = $request->phone_number;

            $user = AccountManager::users()->add($input);
            $user->addToDefaultUserGroups();
            $user->permissions()->updateUserAccessType();

            $new_user_code = new NewUserCode;
            $new_user_code->organization()->associate($organization);
            $new_user_code->generate($user->attribute('username'));
            $new_user_code->status = 'pending';
            $new_user_code->expires_at = now()->addHours(72);
            $new_user_code->save();

            $code = $new_user_code->code;

            if ($organization->parent_organization_id) {
                $suborg_user = new SuborgUser;
                $suborg_user->organization()->associate($organization);
                $suborg_user->username = $input['username'];
                $suborg_user->save();
            }

            event(new UserCreated($user));
        } finally {
            $lock->release();
        }

        if ($organization->setting('step') === 2) {
            $organization->updateSetting('step', 3);
            $organization->save();

            return to_route('users.permissions.edit', ['user' => $user->attribute('username')])->with('reset_step', true);
        }

        return to_route('users.permissions.edit', ['user' => $user->attribute('username')]);
    }

    public function edit($userid)
    {
        $user = AccountManager::users()->find($userid);
        Gate::authorize('view-user', $user);
        Gate::authorize('edit-user', $user);

        $organization = auth()->user()->organization;
        $suborganizations = $organization->suborganizations;
        $organizations = collect([$organization])->merge($suborganizations);

        $user_storage = [];
        foreach ($user->allUserApps() as $app) {
            if (Gate::allows('add-additional-app-storage', [$user, $app])) {
                $additional_storage = new AdditionalStorageService($organization, 'user', $userid, $app);
                $additional_storage_options = $additional_storage->additionalStorageUserOptions($user->appUserAccessType($app));

                $user_storage[$app->id] = [
                    'app_name' => $app->application->name,
                    'id' => $app->id,
                    'amount' => $additional_storage->quota().' GB',
                    'quantity' => $additional_storage->quantity(),
                    'options' => $additional_storage_options,
                ];
            }
        }

        $user_emails = UserEmails::for($user);
        $mailbox = app(MailboxService::class)->forUser($user->attribute('username'));

        $email_domains = [];
        $add_email_account = false;
        // One organization address per user: only someone who has none (not even an offer) can be given one
        if (! $mailbox && auth()->user()->can('add-user-email-account', $user->attribute('username'))) {
            $add_email_account = true;

            $email_domains = $organization->domains()->emailEnabled()->get()->map(function ($email_domain) {
                return [
                    'id' => $email_domain->id,
                    'name' => $email_domain->name,
                ];
            })->values();
        }

        return inertia('Organization/Users/UserEdit', [
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'phone_number' => $user->attribute('phone_number'),
                'personal_email' => $user_emails['personal_email'],
                'org_email' => $user_emails['org_email'],
                // Offered by an administrator and not accepted by the user yet
                'offered_email' => $mailbox?->status === Mailbox::PENDING ? $mailbox->address : null,
                'additional_storage' => $user_storage,
                'organization' => $user->organization()->id,
                'can' => [
                    'add_email_account' => $add_email_account,
                ],
                'url' => [
                    'show' => '/users/'.$user->attribute('username'),
                    'edit' => '/users/'.$user->attribute('username').'/edit',
                    'permissions' => '/users/'.$user->attribute('username').'/permissions',
                ],
            ],
            'organizations' => $organizations->map(function ($organization) {
                return [
                    'id' => $organization->id,
                    'name' => $organization->name,
                ];
            }),
            'email_domains' => $email_domains,
            'breadcrumbs' => [
                [
                    'label' => 'Users',
                    'url' => '/users',
                ],
                [
                    'label' => $user->attribute('name'),
                ],
            ],
        ]);
    }

    public function update(Request $request, $userid)
    {
        $user = AccountManager::users()->find($userid);
        Gate::authorize('view-user', $user);
        Gate::authorize('edit-user', $user);

        $organization = auth()->user()->organization;

        // Someone with an organization address signs in with it: it is theirs to change, not the administrator's
        $has_mailbox = app(MailboxService::class)->active($userid) !== null;

        /* Validate */
        $rules = [
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'phone_number' => [new MainContact($userid, $organization)],
            'additional_storage' => 'nullable|array',
            'organization' => [
                function (string $attribute, mixed $value, \Closure $fail) use ($organization) {
                    $new_organization = \App\Organization::find($value);
                    if ($new_organization && ($new_organization->is($organization) || $new_organization->parent_organization_id === $organization->id)) {
                        return true;
                    }

                    $fail(__('messages.rule.organization'));
                },
            ],
        ];

        if (! $has_mailbox) {
            $rules['personal_email'] = ['email:rfc,filter', 'required', new AccountEmailChecks, new EmailAddressExists($userid)];
        }

        $validatedData = $request->validate($rules);

        $new_organization = \App\Organization::find($validatedData['organization']);

        // Update user storage
        $additional_storage_changed = false;
        if (array_key_exists('additional_storage', $validatedData) && is_array($validatedData['additional_storage'])) {
            $apps = AppInstance::findMany(array_keys($validatedData['additional_storage']))
                ->filter(fn ($app) => $app->belongsToOrganization($organization));
            foreach ($apps as $app) {
                $additional_storage = new AdditionalStorageService($organization, 'user', $user->attribute('username'), $app);
                $additional_storage->updateQuantity(Arr::get($validatedData, "additional_storage.{$app->id}"));
                if ($additional_storage->hasUpdated()) {
                    $additional_storage_changed = true;
                }
            }
        }

        // Process user options on an app-by-app basis to make necessary adjustments directly through the apps API
        $active_apps = $organization->active_apps();
        foreach ($active_apps as $app) {
            Action::dispatch($app->application->slug, 'process_user_options', [$app, $user, $request->all()]);
        }

        if ($additional_storage_changed) {
            Action::execute(new SubscriptionUpdate($organization->parent ?? $organization, Subscription::all()), background: true);
        }

        $name = $request->first_name.' '.$request->last_name;
        $attributes = [
            'phone_number' => str_replace('_', '', $request->phone_number),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'name' => $name,
        ];

        if (! $has_mailbox) {
            $attributes['email'] = $request->personal_email;
        }

        $user->update($attributes);
        $user->updateOrganization($new_organization);
        $user->save();

        event(new UserUpdated($user));

        return to_route('users.show', ['user' => $user->attribute('username')])->with('success', __('organization.user.updated', ['name' => $name]));
    }

    public function destroy(string $userid)
    {
        $user = AccountManager::users()->find($userid);
        Gate::authorize('view-user', $user);
        Gate::authorize('delete-user', $user);

        $organization = auth()->user()->organization;

        event(new DeletingUser($user));
        $user->delete();

        Action::execute(new SubscriptionUpdate($organization->parent ?? $organization, Subscription::refresh()), background: true);
        event(new UserDeleted($organization));

        return redirect('/users')->with('success', __('organization.user.removed', ['user' => $userid]));
    }

    /**
     * An administrator offers the user an organization address (their username at the domain).
     * Nothing about the user changes until they accept it from their profile: switching what
     * someone signs in with can orphan their accounts in apps, so it is theirs to decide.
     */
    public function createAccountEmail(string $username, OrgDomain $domain, MailboxService $mailboxes)
    {
        $user = AccountManager::users()->find($username);
        Gate::authorize('view-user', $user);
        Gate::authorize('edit-user', $user);
        Gate::authorize('add-user-email-account-to-domain', [$username, $domain]);

        $local_part = Str::lower($username);

        if (Validator::make(['local_part' => $local_part], ['local_part' => [new EmailLocalPart]])->fails()) {
            return redirect('/users/'.$username)->with('error', __('messages.rule.email_local_part'));
        }

        try {
            $mailbox = $mailboxes->offer($user, $domain, $local_part, $user->attribute('name'));
        } catch (MailboxException $e) {
            return redirect('/users/'.$username)->with('error', $e->getMessage());
        }

        return redirect('/users/'.$username)->with('success', __('organization.email.mailbox.offered', ['name' => $user->attribute('name'), 'email' => $mailbox->address]));
    }

    public function removeAccountEmail(string $username, string $email_address, MailboxService $mailboxes)
    {
        $user = AccountManager::users()->find($username);
        Gate::authorize('view-user', $user);
        Gate::authorize('edit-user', $user);

        $mailbox = $mailboxes->forUser($username);

        if (! $mailbox || strcasecmp($mailbox->address, $email_address) !== 0) {
            return redirect('/users/'.$username)->with('error', __('organization.user.denied.email_failed'));
        }

        try {
            $mailboxes->remove($user, $mailbox);
        } catch (MailboxException $e) {
            return redirect('/users/'.$username)->with('error', $e->getMessage());
        }

        return redirect('/users/'.$username)->with('success', __('organization.email.mailbox.removed', ['email' => $email_address]));
    }

    public function resetPassword(string $username)
    {
        $user = AccountManager::users()->find($username);
        Gate::authorize('edit-user', $user);

        $new_user_code = NewUserCode::where('username', $username)->first();

        if (! $new_user_code) {
            $new_user_code = new NewUserCode;
        }
        $new_user_code->organization_id = auth()->user()->organization->id;
        $new_user_code->generate($user->attribute('username'));
        $new_user_code->status = 'pending';
        $new_user_code->activated = false;
        $new_user_code->expires_at = now()->addHours(72);
        $new_user_code->save();
        $user->notify(new ResetPassword($user, $new_user_code->code));

        return redirect('/users')->with('success', 'Password Reset Link Sent');
    }
}
