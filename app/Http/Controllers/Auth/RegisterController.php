<?php

namespace App\Http\Controllers\Auth;

use App\Actions\Organizations\SubscriptionUpdate;
use App\Enums\AccessType;
use App\Events\OrganizationRegistered;
use App\Http\Controllers\Controller;
use App\Notifications\WelcomeNewOrganization;
use App\Plan;
use App\Rules\EmailAddressExists;
use App\Rules\UserNotExists;
use App\Services\SubscriptionService;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Action;
use App\Support\Facades\Organization;
use App\Support\Facades\Settings;
use App\Support\Organizations;
use App\User;
use Illuminate\Foundation\Auth\RegistersUsers;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;
use Symfony\Component\Mailer\Exception\UnexpectedResponseException;

class RegisterController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Register Controller
    |--------------------------------------------------------------------------
    |
    | This controller handles the registration of new users as well as their
    | validation and creation. By default this controller uses a trait to
    | provide this functionality without requiring any additional code.
    |
    */

    use RegistersUsers;

    /**
     * Where to redirect users after registration.
     *
     * @var string
     */
    protected $redirectTo = '/registered';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('guest');
    }

    /**
     * Show the application registration form.
     *
     * @return View
     */
    public function showRegistrationForm()
    {
        if (! $default_plan = Plan::where('is_default', 1)->count() > 0) {
            Log::critical(__('auth.denied.no_plans'));
        }

        $org_types = Plan::whereNot('org_type', '')->where('archive', 0)->groupBy('org_type')->get()->map(function ($plan) {
            $org_types = Organizations::types();

            return [
                'name' => $org_types[$plan->org_type],
                'value' => $plan->org_type,
            ];
        });

        return inertia('Auth/AccountSignup', [
            'can' => [
                'register' => $default_plan,
            ],
            'terms_url' => Settings::get('terms_url'),
            'base_domain' => Settings::get('base_domain'),
            'org_types' => $org_types,
        ]);
    }

    /**
     * Get a validator for an incoming registration request.
     *
     * @return \Illuminate\Contracts\Validation\Validator
     */
    protected function validator(array $data)
    {
        return Validator::make($data, [
            'username' => ['required', 'string', 'min:5', 'max:30', 'alpha_num', 'lowercase', 'unique:users,username', new UserNotExists],
            'contact_email' => ['required', 'string', 'max:100', 'email', new EmailAddressExists, 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised(2)],
            'contact_first_name' => ['required', 'string', 'max:100'],
            'contact_last_name' => ['required', 'string', 'max:100'],
            'contact_phone_number' => ['required', 'string', 'max:30'],
            'subdomain' => ['required', 'string', 'max:30', 'alpha_dash', 'unique:organizations,slug'],
            'name' => ['required_unless:type,none', 'max:100'],
            'type' => ['required', 'string', 'in:nonprofit,business,none'],
            'terms_of_use' => 'required|accepted',
        ]);
    }

    /**
     * Create a new user instance after a valid registration.
     *
     * @return User
     */
    protected function create(array $data)
    {
        if (! $default_plan = Plan::where('is_default', 1)->first()) {
            return to_route('auth.login');
        }

        $data['subdomain'] = strtolower($data['subdomain']);
        try {
            // Create organization in database
            $organization = new \App\Organization;
            $organization->slug = $data['subdomain'];
            $organization->api_token = Str::random(60);
            $organization->name = Arr::get($data, 'name') ?? $data['contact_first_name'].' '.$data['contact_last_name'];
            $organization->description = Arr::get($data, 'name') ?? __('labels.none_org_type');
            $organization->email = $data['contact_email'];
            $organization->phone_number = $data['contact_phone_number'];
            $organization->contact_first_name = $data['contact_first_name'];
            $organization->contact_last_name = $data['contact_last_name'];
            $organization->contact_email = $data['contact_email'];
            $organization->secretpw = Str::password(20, true, true, false, false);
            $organization->type = $data['type'];
            $organization->settings = [
                'step' => 0,
            ];
            $organization->status = 'new';
            $organization->save();

            // Add user
            $user = new User;
            $user->username = $data['username'];
            $user->first_name = $data['contact_first_name'];
            $user->last_name = $data['contact_last_name'];
            $user->name = $data['contact_first_name'].' '.$data['contact_last_name'];
            $user->email = $data['contact_email'];
            $user->phone_number = $data['contact_phone_number'];
            $user->password = Hash::make($data['password']);
            $user->access_type = AccessType::STANDARD;
            $user->organization()->associate($organization);
            $user->is_allowed = true;
            $user->save();

            $organization->primary_contact()->associate($user);
            $organization->save();

            $account = AccountManager::accounts();

            $account->create($organization);
            $organization_user = $account->users($organization)->add([
                'username' => $data['username'],
                'first_name' => $data['contact_first_name'],
                'last_name' => $data['contact_last_name'],
                'name' => $data['contact_first_name'].' '.$data['contact_last_name'],
                'email' => $data['contact_email'],
                'password' => $data['password'],
                'phone_number' => $data['contact_phone_number'],
            ]);
            $organization_user->permissions()->addControlPanelAccess($user);
            $organization_user->permissions()->addBillingManagerAccess();

            // Check if only one plan option available that doesn't require stripe
            // In this case, it will automatically subscribe the organization
            $plan = (new SubscriptionService($organization))->all()->updateBase($default_plan);

            Action::execute(new SubscriptionUpdate($organization, $plan), background: true);
        } catch (\Throwable $e) {
            report($e);
            AccountManager::account($organization)->destroy();
            $organization->domains()->delete();
            if (isset($user)) {
                $user->delete();
            }
            $organization->delete();
            throw new \Exception($e->getMessage().$e->getTraceAsString());
        }

        // Create the user in database
        return [
            'user' => $user,
            'organization' => $organization,
        ];
    }

    /**
     * Handle a registration request for the application.
     *
     * @return RedirectResponse|JsonResponse
     */
    public function register(Request $request)
    {
        $this->validator($request->all())->validate();

        $registration_info = $this->create($request->all());

        try {
            event(new OrganizationRegistered($registration_info['organization'], $registration_info['user']));
        } catch (UnexpectedResponseException $e) {
            report($e);

            return redirect('/');
        }

        $this->guard()->login($registration_info['user']);
        $registration_info['user']->notify(new WelcomeNewOrganization($registration_info['user']->organization));

        if ($response = $this->registered($request, $registration_info['user'])) {
            return $response;
        }

        return $request->wantsJson()
                    ? new JsonResponse([], 201)
                    : redirect($this->redirectPath());
    }
}
