<?php

// Self-registration (/public/org/{slug}/register). How often the first step may be used.
return [
    'throttle' => [
        // Per organization and IP address. Counted once an email is going to be sent, not for a
        // mistyped address or a failed captcha
        'ip' => [
            'attempts' => 5,
            'minutes' => 10,
        ],

        // Per organization and email address, whoever asks. Over it, the page answers as it always
        // does and sends nothing, so it says nothing about the address
        'email' => [
            'attempts' => 3,
            'minutes' => 60,
        ],
    ],
];
