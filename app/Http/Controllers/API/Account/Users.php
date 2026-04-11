<?php

namespace App\Http\Controllers\API\Account;

use App\Http\Controllers\Controller;
use App\NewUserCode;
use App\Notifications\UserCreated;
use App\Organization;
use App\Rules\AccountEmailChecks;
use App\Rules\AppExists;
use App\Rules\EmailAddressExists;
use App\Rules\UserNotExists;
use App\Support\Facades\AccountManager;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class Users extends Controller
{
    public function list()
    {

        return response()->json();
    }

    public function store(Request $request)
    {
        /* Validate */
        $validatedData = $request->validate([
            'username' => ['required', 'alpha_num', new UserNotExists],
            'first_name' => 'required|max:100',
            'last_name' => 'required|max:100',
            'email' => ['email', 'required', new AccountEmailChecks, new EmailAddressExists],
            'phone' => '',
            'source' => ['required', new AppExists],
        ]);

        $organization = Organization::account();

        $input['username'] = $request->username;
        $input['first_name'] = $request->first_name;
        $input['last_name'] = $request->last_name;
        $input['email'] = $request->email;
        $input['password'] = Str::password(20, true, true, false, false);
        $input['phone_number'] = $request->phone;

        $user = AccountManager::users()->add($input);
        $user->addToDefaultUserGroups();
        $user->permissions()->updateUserAccessType();

        $new_user_code = new NewUserCode;
        $new_user_code->organization()->associate($organization);
        $new_user_code->generate($user->attribute('username'));
        $new_user_code->save();

        $code = $new_user_code->code;

        $user->get()->notify(new UserCreated($user, $code));

        return response()->json([
            'response' => 'success',
        ]);
    }
}
