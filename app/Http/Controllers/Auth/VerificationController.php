<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\Events\Verified;
use Illuminate\Foundation\Auth\VerifiesEmails;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

class VerificationController extends Controller
{
    /*
    |--------------------------------------------------------------------------
    | Email Verification Controller
    |--------------------------------------------------------------------------
    |
    | This controller is responsible for handling email verification for any
    | user that recently registered with the application. Emails may also
    | be re-sent if the user didn't receive the original email message.
    |
    */

    use VerifiesEmails;

    /**
     * Where to redirect users after verification.
     *
     * @var string
     */
    protected $redirectTo = '/';

    /**
     * Create a new controller instance.
     *
     * @return void
     */
    public function __construct()
    {
        $this->middleware('auth')->only(['show', 'resend']);
        $this->middleware('signed')->only('verify');
        $this->middleware('throttle:6,1')->only('verify', 'resend');
    }

    /**
     * Show the email verification notice.
     *
     * @return RedirectResponse|View
     */
    public function show(Request $request)
    {
        $user = $request->user();

        return $user->hasVerifiedEmail()
                        ? redirect($this->redirectPath())
                        : inertia('Auth/VerifyEmail', [
                            'user' => [
                                'name' => $user->name,
                                'email' => $user->email,
                            ],
                            'resent' => session('resent'),
                        ]);
    }

    /**
     * Mark the email address identified by the signed link as verified, then
     * sign the user in. The link is opened from an email client, so the
     * browser following it usually isn't authenticated as anyone yet.
     *
     * @return JsonResponse|RedirectResponse
     *
     * @throws AuthorizationException
     */
    public function verify(Request $request)
    {
        $user = User::find($request->route('id'));

        if (! $user || ! hash_equals((string) $request->route('hash'), sha1($user->emailForVerification()))) {
            throw new AuthorizationException;
        }

        $alreadyVerified = $user->hasVerifiedEmail();

        if (! $alreadyVerified && $user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        if (Auth::id() !== $user->id) {
            Auth::login($user);
            $request->session()->regenerate();
        }

        if ($alreadyVerified) {
            return $request->wantsJson()
                        ? new JsonResponse([], 204)
                        : redirect($this->redirectPath());
        }

        return $request->wantsJson()
                    ? new JsonResponse([], 204)
                    : redirect($this->redirectPath())->with('verified', true);
    }
}
