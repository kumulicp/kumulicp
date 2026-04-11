<?php

namespace App\Http\Controllers;

use App\Rules\AccountEmailChecks;
use App\Rules\ConfirmOldPassword;
use App\Rules\EmailAddressExists;
use App\Rules\MainContact;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Validation\Rules\Password;

class Profile extends Controller
{
    public function index()
    {
        $profile = auth()->user();

        return inertia('Organization/Profile/ProfileEdit', [
            'profile' => [
                'id' => $profile->username,
                'first_name' => $profile->first_name,
                'last_name' => $profile->last_name,
                'personal_email' => $profile->email,
                'phone_number' => $profile->phone_number,
            ],
        ]);
    }

    public function update(Request $request)
    {
        $user = auth()->user();
        $organization = $user->organization;
        /* Validate */
        $validatedData = $request->validate([
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'personal_email' => ['email', 'required', new AccountEmailChecks, new EmailAddressExists($user->username)],
            'phone_number' => [new MainContact($user, $organization)],
        ]);

        $user->email = $request->personal_email;
        $user->phone_number = $request->phone_number;
        $user->first_name = $request->first_name;
        $user->last_name = $request->last_name;
        $user->name = $request->first_name.' '.$request->last_name;
        $user->save();

        $validatedData['name'] = $validatedData['first_name'].' '.$validatedData['last_name'];

        if (AccountManager::driver() != 'direct') {
            AccountManager::users()->find($user->username)->update($validatedData);
        }

        return redirect('/profile')->with('success', __('profile.updated'));
    }

    public function updatePassword(Request $request)
    {
        $user = auth()->user();

        /* Validate */
        $validatedData = $request->validate([
            'current_password' => ['required', 'string', 'min:8', new ConfirmOldPassword($user->email)],
            'password' => ['required', 'string', 'confirmed', Password::min(8)->letters()->mixedCase()->numbers()->symbols()->uncompromised()],
        ]);

        // Validate the input and return correct response
        $user = AccountManager::users()->find($user->username);
        $user->setPassword($validatedData['password']);
        $user->save();

        return redirect('/profile')->with('success', __('profile.password.updated'));

    }
}
