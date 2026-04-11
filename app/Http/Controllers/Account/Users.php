<?php

namespace App\Http\Controllers\Account;

use App\Actions\Organizations\SubscriptionUpdate;
use App\AppInstance;
use App\Events\Users\DeletingUser;
use App\Events\Users\UserCreated;
use App\Events\Users\UserDeleted;
use App\Events\Users\UserUpdated;
use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Notifications\ResetPassword;
use App\OrgDomain;
use App\Rules\AccountEmailChecks;
use App\Rules\EmailAddressExists;
use App\Rules\MainContact;
use App\Rules\UserNotExists;
use App\Services\AdditionalStorageService;
use App\SuborgUser;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Domain;
use App\Support\Facades\Organization;
use App\Support\Facades\Subscription;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class Users extends Controller
{
    public function index()
    {
        $organization = auth()->user()->organization;
        $minimal_label = Subscription::base()->setting('base.minimal_label');
        $basic_label = Subscription::base()->setting('basic.name');
        $access_types = [
            'standard' => __('labels.user'),
            'basic' => $basic_label,
            'minimal' => $minimal_label,
            'none' => __('labels.deactivated'),
        ];

        return inertia('Organization/Users/UsersList', [
            'users' => AccountManager::users()->collect()->map(function ($user, $value) {
                return [
                    'id' => $user->attribute('username'),
                    'name' => $user->attribute('name'),
                    'personal_email' => $user->attribute('personal_email'),
                    'org_email' => $user->attribute('org_email'),
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
        $this->authorize('view-user', [$user]);

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

        return inertia('Organization/Users/UserView', [
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'phone_number' => $user->attribute('phone_number'),
                'personal_email' => $user->attribute('personal_email'),
                'org_email' => $user->attribute('org_email'),
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
        $userid = $request->username;
        $organization = auth()->user()->organization;

        $user = AccountManager::users()->find($userid);
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
        $this->authorize('add-user');

        $organization = auth()->user()->organization;

        /* Validate */
        $validatedData = $request->validate([
            'username' => ['required', 'alpha_num', 'lowercase', new UserNotExists],
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'personal_email' => ['email', 'required', new AccountEmailChecks, new EmailAddressExists],
            'account_email' => '',
            'phone_number' => '',
        ]);

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
        $new_user_code->save();

        $code = $new_user_code->code;

        if ($organization->parent_organization_id) {
            $suborg_user = new SuborgUser;
            $suborg_user->organization()->associate($organization);
            $suborg_user->username = $input['username'];
            $suborg_user->save();
        }

        event(new UserCreated($user));

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
        $this->authorize('view-user', $user);
        $this->authorize('edit-user', $user);

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

        $email_domains = [];
        $add_email_account = false;
        if (auth()->user()->can('add-user-email-account', $user->attribute('username'))) {
            $add_email_account = true;
            $emails = $user->attribute('org_email', 'array');

            $email_domains = $organization->domains()->emailEnabled()->get()->map(function ($email_domain) {
                return [
                    'id' => $email_domain->id,
                    'name' => $email_domain->name,
                ];
            })
                ->filter(function (array $domain, int $key) use ($emails) {
                    $use = true;
                    if ($emails) {
                        foreach ($emails as $email) {
                            if (strpos($email, $domain['name'])) {
                                $use = false;
                            }
                        }
                    }

                    return $use ? $domain : false;
                });
        }

        return inertia('Organization/Users/UserEdit', [
            'user' => [
                'id' => $user->attribute('username'),
                'name' => $user->attribute('name'),
                'first_name' => $user->attribute('first_name'),
                'last_name' => $user->attribute('last_name'),
                'phone_number' => $user->attribute('phone_number'),
                'personal_email' => $user->attribute('email'),
                'org_emails' => $user->attribute('org_email'),
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
        $this->authorize('view-user', $user);
        $this->authorize('edit-user', $user);

        $organization = auth()->user()->organization;

        /* Validate */
        $validatedData = $request->validate([
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'personal_email' => ['email', 'required', new AccountEmailChecks, new EmailAddressExists($userid)],
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
        ]);

        $new_organization = \App\Organization::find($validatedData['organization']);

        // Update user storage
        if (array_key_exists('additional_storage', $validatedData) && is_array($validatedData['additional_storage'])) {
            $apps = AppInstance::findMany(array_keys($validatedData['additional_storage']));
            $additional_storage_changed = false;
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
        $user->update([
            'phone_number' => str_replace('_', '', $request->phone_number),
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'name' => $name,
            'email' => $request->personal_email,
        ]);
        $user->updateOrganization($new_organization);
        $user->save();

        event(new UserUpdated($user));

        return to_route('users.show', ['user' => $user->attribute('username')])->with('success', __('organization.user.updated', ['name' => $name]));
    }

    public function destroy(string $userid)
    {
        $user = AccountManager::users()->find($userid);
        $this->authorize('view-user', $user);
        $this->authorize('delete-user', $user);

        $organization = auth()->user()->organization;

        event(new DeletingUser($user));
        $user->delete();

        Action::execute(new SubscriptionUpdate($organization->parent ?? $organization, Subscription::refresh()));
        event(new UserDeleted($organization));

        return redirect('/users')->with('success', __('organization.user.removed', ['user' => $userid]));
    }

    public function createAccountEmail(string $username, OrgDomain $domain)
    {
        $user = AccountManager::users()->find($username);
        $this->authorize('view-user', $user);
        $this->authorize('edit-user', $user);
        $this->authorize('add-user-email-account-to-domain', [$username, $domain]);

        try {
            $email_server = Domain::connect($domain, 'email');
            $add_email_response = $email_server->createUserEmail($user, $domain);
        } catch (Throwable $e) {
            report($e);
            Log::critical($e->getMessage(), ['organization_id' => $domain->organization_id]);

            return redirect('/users/'.$username)->with('error', __('organization.user.denied.email_failed'));
        }

        return redirect('/users/'.$username)->with('success', __('organization.user.email.created', ['user' => $user->attribute('name')]));
    }

    public function removeAccountEmail(string $username, string $email_address)
    {
        $user = AccountManager::users()->find($username);
        $this->authorize('view-user', $user);
        $this->authorize('edit-user', $user);

        $split = explode('@', $email_address);
        $domain = OrgDomain::where('name', $split[1])->first();

        $email_server = Domain::connect($domain, 'email');
        $email_server->deleteUserEmail($user, $email_address);

        return redirect('/users/'.$username)->with('success', __('organization.user.email.removed', ['user' => $user->attribute('name')]));
    }

    public function resetPassword(string $username)
    {
        $user = AccountManager::users()->find($username);
        $this->authorize('view-user', $user);

        $new_user_code = NewUserCode::where('username', $username)->first();

        if (! $new_user_code) {
            $new_user_code = new NewUserCode;
        }
        $new_user_code->organization_id = auth()->user()->organization->id;
        $new_user_code->generate($user->attribute('username'));
        $new_user_code->status = 'pending';
        $new_user_code->activated = false;
        $new_user_code->save();
        $user->notify(new ResetPassword($user, $new_user_code->code));

        return redirect('/users')->with('success', 'Password Reset Link Sent');
    }
}
