<?php

namespace App\Support;

class EmailHelper
{
    public static function parseEmail($email)
    {
        $email_parts = explode('@', $email);

        return [
            'username' => $email_parts[0],
            'domain' => $email_parts[1],
            'address' => $email,
        ];
    }
}
