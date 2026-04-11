<?php

namespace App\Http\Controllers\Pub;

use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Rules\ConfirmOldPassword;
use App\Support\Facades\AccountManager;
use App\Support\Facades\Organization;
use App\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class ChangePassword extends Controller
{
    public function edit($organization, $email)
    {
        return inertia('Auth/SetPassword', [
            'email' => $email,
        ]);
    }

    public function set($code)
    {
        $new_user = NewUserCode::where('code', $code)->first();
        Organization::setOrganization($new_user->organization);

        if ($new_user && $new_user->activated == 0) {
            $user = AccountManager::users()->find($new_user->username);

            return inertia('Auth/SetPassword', [
                'code' => $code,
                'user' => [
                    'email' => $user->attribute('email'),
                ],
            ]);
        } elseif ($new_user && $new_user->activated == 1) {
            return redirect('/public/users/done/'.$code);
        } else {
            return abort(404);
        }
    }

    public function store(Request $request, $code)
    {
        /* Validate */
        $validatedData = $request->validate([
            'password' => ['required', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        $new_user = NewUserCode::where('code', $code)->first();

        Organization::setOrganization($new_user->organization);

        $user = AccountManager::users()->find($new_user->username);
        $user->setPassword($request->input('password'));
        $user->save();

        $new_user->activated = 1;
        $new_user->save();

        return redirect('/public/users/done/'.$code);
    }

    public function update(Request $request, $organization, $email)
    {
        /* Validate */
        $validatedData = $request->validate([
            'currentPassword' => ['required', new ConfirmOldPassword($organization, $email)],
            'newPassword' => 'required|confirmed',
        ]);

        $user = AccountManager::users()->findEmail($email);
        $user->setPassword($request->input('newPassword'));
        $user->save();

        return redirect('public/users/done')->with('success', __('auth.password.updated'));
    }

    public function done($code)
    {
        $new_user = NewUserCode::where('code', $code)->first();
        if ($db_user = User::where('username', $new_user->username)->first()) {
            $db_user->email_verified_at = now();
            $db_user->save();

        }
        Organization::setOrganization($new_user->organization);

        if (! $new_user) {
            return redirect(route('login'));
        }

        $user = AccountManager::users()->find($new_user->username);

        if (! $user) {
            return redirect(route('login'));
        }

        return inertia('Auth/WelcomePage', [
            'user' => [
                'name' => $user->attribute('name'),
                'apps' => collect($user->apps())->map(function ($app) {
                    return [
                        'slug' => $app->application->slug,
                        'name' => $app->application->name,
                        'description' => $app->application->short_description,
                        'address' => $app->admin_address(),
                    ];
                }),
                'can' => [
                    'admin' => $user->permissions()->hasControlPanelAccess(),
                ],
            ],
        ]);
    }
}
