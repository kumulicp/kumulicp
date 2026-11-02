<?php

return [
    'title' => 'Support tickets',
    'new_ticket' => 'New ticket',
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

    'errors' => [
        'internal_staff_only' => 'Only support staff can add internal notes.',
        'closed' => 'This ticket is closed. Reopen it to reply.',
        'cannot_edit' => 'This can no longer be edited.',
        'invalid_transition' => 'The ticket cannot be moved to that status.',
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
        'assignee_removed' => [
            'title' => ':reference needs someone to look after it',
            'line' => 'The person responsible for ":subject" is no longer available. Please assign a new person on your side.',
        ],
    ],
];
