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
    /**
     * @OA\Get(
     *     path="/users",
     *     summary="List users",
     *     description="Returns a list of users belonging to the authenticated organization.",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\Response(
     *         response=200,
     *         description="List of users",
     *         @OA\JsonContent(type="array", @OA\Items(type="object"))
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated")
     * )
     */
    public function list()
    {

        return response()->json();
    }

    /**
     * @OA\Post(
     *     path="/users",
     *     summary="Create a user",
     *     description="Creates a new user account within the authenticated organization and sends a welcome notification.",
     *     tags={"Users"},
     *     security={{"bearerAuth":{}}},
     *     @OA\RequestBody(
     *         required=true,
     *         @OA\JsonContent(
     *             required={"username","first_name","last_name","email","source"},
     *             @OA\Property(property="username", type="string", description="Alphanumeric username. Must not already exist."),
     *             @OA\Property(property="first_name", type="string", maxLength=100),
     *             @OA\Property(property="last_name", type="string", maxLength=100),
     *             @OA\Property(property="email", type="string", format="email"),
     *             @OA\Property(property="phone", type="string", description="Optional phone number."),
     *             @OA\Property(property="source", type="string", description="Slug of the application granting access. Must be a valid registered app.")
     *         )
     *     ),
     *     @OA\Response(
     *         response=200,
     *         description="User created successfully",
     *         @OA\JsonContent(
     *             @OA\Property(property="response", type="string", example="success")
     *         )
     *     ),
     *     @OA\Response(response=401, description="Unauthenticated"),
     *     @OA\Response(response=422, description="Validation error")
     * )
     */
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
