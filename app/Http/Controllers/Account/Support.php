<?php

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Mail\SupportTicket;
use App\Support\Facades\Settings;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Throwable;

class Support extends Controller
{
    public function submit(Request $request)
    {
        /* Validate */
        $validatedData = $request->validate([
            'subject' => 'required|max:255',
            'body' => 'required|max:1000',
            'request' => 'required|in:question,bug,feature',
        ]);

        $support_email = Settings::get('support_email');

        if ($support_email) {
            try {
                Mail::mailer('errors')->to($support_email)
                    ->send(new SupportTicket(title: $validatedData['subject'], body: $validatedData['body'], request: $validatedData['request']));

                return response()->json([
                    'success' => 'success',
                    'text' => __('organization.support.success'),
                ], 200);
            } catch (Throwable $e) {
                report($e);

                return response()->json([
                    'success' => 'error',
                    'text' => __('organization.support.failed')." <a href=\"mailto:{$support_email}\">{$support_email}</a>",
                ], 500);
            }
        }

        return response()->json([
            'success' => 'error',
            'text' => __('organization.support.critical_fail'),
        ], 500);
    }
}
