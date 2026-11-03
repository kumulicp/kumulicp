<?php

return [
    'title' => 'Support tickets',
    'new_ticket' => 'New ticket',
    'preferences_saved' => 'Notification settings saved.',
    'support_label' => 'support',
    'system_label' => 'Support desk',
    'merged_note' => 'This ticket was merged into :target. The conversation continues there.',
    'sla' => [
        'clocks' => ['first_response' => 'first response', 'resolution' => 'resolution'],
    ],
    'rated' => 'Thank you for your feedback.',
    'merged_done' => 'The tickets were merged.',
    'redacted' => 'The message was removed.',
    'deleted_forever' => 'The ticket was deleted.',
    'deleted_user' => 'Deleted user',

    'created' => 'Your ticket has been created.',
    'reply_sent' => 'Your reply has been sent.',
    'note_added' => 'Internal note added.',
    'message_updated' => 'Your message has been updated.',
    'updated' => 'Ticket updated.',
    'closed' => 'The ticket has been closed.',
    'reopened' => 'The ticket has been reopened.',

    'status' => [
        'open' => 'Open',
        'pending_support' => 'Waiting on support',
        'pending_customer' => 'Waiting on you',
        'on_hold' => 'On hold',
        'resolved' => 'Resolved',
        'closed' => 'Closed',
    ],

    'priority' => [
        'low' => 'Low',
        'normal' => 'Normal',
        'high' => 'High',
        'urgent' => 'Urgent',
    ],

    'type' => [
        'question' => 'Question',
        'bug' => 'Problem',
        'feature' => 'Feature request',
    ],

    'impact' => [
        'minor' => 'Minor: a small annoyance',
        'degraded' => 'Degraded: something is not working properly',
        'outage' => 'Outage: something is down',
    ],

    'validation' => [
        'body_required' => 'Please write a message.',
        'body_too_long' => 'The message is too long (maximum :max characters).',
    ],

    'integrations' => [
        'disabled_title' => 'The :name integration was switched off',
        'disabled_line' => 'The :name integration failed too many times in a row, so it was switched off to avoid hammering the other side.',
        'open' => 'Open integrations',
            'title' => 'Integrations',
        'created' => 'Integration created.',
        'saved' => 'Integration saved.',
        'deleted' => 'Integration deleted.',
        'enabled' => 'Integration switched on.',
        'disabled' => 'Integration switched off.',
        'rotated' => 'A new signing secret was made. The old one keeps working for 7 days.',
        'redelivering' => 'Trying again.',
        'not_a_tracker' => 'Choose an issue tracker.',
        'issue_requested' => 'Opening the issue. It will appear here in a moment.',
        'issue_exists' => 'This ticket already has an issue there, or one is on its way.',
        'deliveries' => 'Deliveries',
],

    'time' => [
        'logged' => 'Time logged.',
        'deleted' => 'Time entry removed.',
    ],

    'inbound' => [
        'comment' => ':author commented on :issue in :integration: :text',
        'closed' => 'The linked issue :issue was closed in :integration.',
        'reopened' => 'The linked issue :issue was reopened in :integration.',
        'someone' => 'Someone',
    ],

    'attachments' => [
        'upload_failed' => 'The file could not be uploaded: :reason',
        'not_received' => 'No file was received. It may be larger than the server allows.',
        'not_saved' => 'it could not be saved',
        'empty' => 'That file is empty.',
        'too_large' => 'That file is too large (maximum :max MB).',
        'too_many_waiting' => 'You have too many files waiting to be sent. Send or remove some first.',
        'type_not_allowed' => 'That type of file is not allowed. Allowed: :types.',
        'rejected' => 'That file was rejected by the security scan.',
        'too_many' => 'You can attach at most :max files to one message.',
        'unavailable' => 'One of the attached files is no longer available. Please upload it again.',
        'message_too_large' => 'The attached files are too large together (maximum :max MB per message).',
    ],

    'errors' => [
        'internal_staff_only' => 'Only support staff can add internal notes.',
        'closed' => 'This ticket is closed. Reopen it to reply.',
        'cannot_edit' => 'This can no longer be edited.',
        'invalid_transition' => 'The ticket cannot be moved to that status.',
        'invalid_duration' => 'Enter a length of time between 1 minute and 24 hours, such as 45m, 1h30 or 1.5h.',
        'merged' => 'This ticket was merged into another one.',
        'merge_not_found' => 'There is no ticket with that number.',
        'merge_self' => 'A ticket cannot be merged into itself.',
        'merge_organization' => 'Only tickets from the same organization can be merged.',
        'merge_target_closed' => 'Choose an open ticket to merge into.',
        'cannot_rate' => 'You can rate a ticket once it has been resolved.',
        'invalid_score' => 'Choose a rating from 1 to 5.',
        'invalid_watcher' => 'That person cannot be added to this ticket.',
        'invalid_assignee' => 'That person cannot be assigned to this ticket.',
        'stale' => 'This ticket was changed by someone else. Reload the page and try again.',
    ],

    'admin' => [
        'title' => 'Tickets',
        'category_saved' => 'Category saved.',
        'category_archived' => 'Category archived.',
    ],

    'notifications' => [
        'view' => 'View ticket',
        'rate_prompt' => 'Open the ticket to tell us how we did. It takes a few seconds and helps us improve.',
        'reply_in_panel' => 'Please reply in the control panel. Replies to this email are not added to the ticket.',
        'created' => [
            'title' => 'New ticket :reference',
            'line' => ':name opened the ticket ":subject".',
        ],
        'reply' => [
            'title' => 'New reply on :reference',
            'line' => ':name replied to the ticket ":subject".',
        ],
        'note' => [
            'title' => 'New internal note on :reference',
            'line' => ':name added an internal note to ":subject".',
        ],
        'status' => [
            'title' => ':reference is now :status',
            'line' => 'The status of ":subject" changed to :status.',
        ],
        'assigned' => [
            'title' => ':reference was assigned to you',
            'line' => ':name assigned the ticket ":subject" to you.',
        ],
        'reminder' => [
            'title' => ':reference is waiting for your reply',
            'line' => 'We are waiting to hear from you about ":subject". Reply when you can, or let us know if it is no longer needed.',
        ],
        'merged' => [
            'title' => ':reference was merged into :target',
            'line' => 'The ticket ":subject" was a duplicate and has been merged into :target. The conversation continues there.',
        ],
        'sla_warning' => [
            'title' => ':reference is close to its :clock target',
            'line' => 'The :clock target for ":subject" will run out soon.',
        ],
        'sla_breached' => [
            'title' => ':reference missed its :clock target',
            'line' => 'The :clock target for ":subject" has run out.',
        ],
        'rating' => [
            'title' => ':reference was rated :score out of 5',
            'line' => ':name was not happy with how ":subject" was handled.',
        ],
        'assignee_removed' => [
            'title' => ':reference needs someone to look after it',
            'line' => 'The person responsible for ":subject" is no longer available. Please assign a new person on your side.',
        ],
    ],
];
