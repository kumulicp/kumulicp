<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Foundation\Auth\SendsPasswordResetEmails;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Password;
use Inertia\Response;

class ForgotPasswordController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Password Reset Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling password reset emails and
    | includes a trait which assists in sending these notifications from
    | your application to your users. Feel free to explore this trait.
    |
    */

    use SendsPasswordResetEmails;

    /**
     * Display the form to request a password reset link.
     *
     * @return Response
     */
    public function showLinkRequestForm()
    {
        return inertia('Auth/RecoverPassword');
    }

    /**
     * Get the needed authentication credentials from the request.
     *
     * @return array
     */
    protected function credentials(Request $request)
    {
        return $request->only('email');
    }

    /**
     * Send a reset link to the given user.
     *
     * Always returns the same response whether or not the email is
     * registered, so the endpoint can't be used to enumerate accounts. The
     * real broker outcome is only logged server-side for support visibility.
     */
    public function sendResetLinkEmail(Request $request)
    {
        $this->validateEmail($request);

        $response = $this->broker()->sendResetLink(
            $this->credentials($request)
        );

        Log::info('Password reset link requested.', [
            'email' => $request->input('email'),
            'broker_status' => $response,
        ]);

        return $this->sendResetLinkResponse($request, Password::RESET_LINK_SENT);
    }
}
