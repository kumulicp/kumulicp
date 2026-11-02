<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Ticket reference prefix
    |--------------------------------------------------------------------------
    | Shown before the sequential number, e.g. KCP-1042.
    */
    'prefix' => env('TICKETS_PREFIX', 'KCP'),

    /*
    |--------------------------------------------------------------------------
    | Message editing
    |--------------------------------------------------------------------------
    | An author may edit their own message until a staff member replies after
    | it, and for at most this many minutes.
    */
    'edit_window_minutes' => 15,

    /*
    |--------------------------------------------------------------------------
    | Email coalescing
    |--------------------------------------------------------------------------
    | Reply and note emails wait this long before sending so a burst of replies
    | becomes one email, and are skipped if the person has read them meanwhile.
    | The in-app notification is never delayed.
    */
    'mail_delay_seconds' => (int) env('TICKETS_MAIL_DELAY_SECONDS', 60),

    /*
    |--------------------------------------------------------------------------
    | Legacy support address
    |--------------------------------------------------------------------------
    | While tickets roll out, new tickets are also mailed to the `support_email`
    | server setting. Set TICKETS_COPY_SUPPORT_EMAIL=false once the inbox is
    | in use. This copy will be removed in a later release.
    */
    'copy_support_email' => env('TICKETS_COPY_SUPPORT_EMAIL', true),

    /*
    |--------------------------------------------------------------------------
    | Limits
    |--------------------------------------------------------------------------
    */
    'max_subject_length' => 150,
    'max_body_characters' => 50000,

    // "attempts,minutes" for the throttle middleware.
    'throttle' => [
        'create' => '10,60',
        'reply' => '30,60',
        'upload' => '60,60',
        // Polling from open pages: ticket page every 10 s, list and badge every 30 s
        'poll' => '240,1',
    ],

    /*
    |--------------------------------------------------------------------------
    | Attachments
    |--------------------------------------------------------------------------
    | Files live on a private disk and are only ever served through an
    | authorized route. Sizes are bounded by PHP's upload_max_filesize /
    | post_max_size and by the proxy in front of the app as well, so raise
    | those too when raising these.
    */
    'attachments' => [
        'disk' => env('TICKETS_ATTACHMENT_DISK', 'tickets'),
        'max_file_kb' => (int) env('TICKETS_ATTACHMENT_MAX_FILE_KB', 10240),
        'max_files' => (int) env('TICKETS_ATTACHMENT_MAX_FILES', 10),
        'max_message_kb' => (int) env('TICKETS_ATTACHMENT_MAX_MESSAGE_KB', 25600),
        // Uploads that were never sent with a message are deleted after this long
        'orphan_hours' => 24,
        // Most uploads one person may have waiting to be sent
        'max_drafts_per_user' => 50,
        // Class implementing App\Contracts\Tickets\AttachmentScanner (e.g. a ClamAV
        // client). Null means files are not scanned.
        'scanner' => env('TICKETS_ATTACHMENT_SCANNER'),
    ],
];
