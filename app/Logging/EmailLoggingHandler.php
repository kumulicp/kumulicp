<?php

namespace App\Logging;

use App\Mail\CriticalError;
use App\Organization;
use App\Support\Facades\Settings;
use App\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class EmailLoggingHandler extends AbstractProcessingHandler
{
    /**
     * Reference:
     * https://github.com/markhilton/monolog-mysql/blob/master/src/Logger/Monolog/Handler/MysqlHandler.php
     */
    public function __construct($level = Logger::ERROR, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        if (Auth::check()) {
            $user = auth()->user();
            if (is_a($user, User::class)) {
                $organization_id = $user->organization->id;
            } elseif (is_a($user, Organization::class)) {
                $organization_id = $user->id;
            } else {
                $organization_id = 1;
            }
        } else {
            $organization_id = '';
        }
        if (in_array('REMOTE_ADDR', $_SERVER, true) && in_array('HTTP_USER_AGENT', $_SERVER, true)) {
            $remote_addr = $_SERVER['REMOTE_ADDR'];
            $http_user_agent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $remote_addr = '';
            $http_user_agent = '';
        }

        $data = [
            'message' => $record['message'],
            'organization_id' => $organization_id,
            'context' => $record['context'],
            'level' => $record['level'],
            'level_name' => $record['level_name'],
            'channel' => $record['channel'],
            'record_datetime' => $record['datetime']->format('Y-m-d H:i:s'),
            'extra' => $record['extra'],
            'formatted' => $record['formatted'],
            'remote_addr' => $remote_addr,
            'user_agent' => $http_user_agent,
            'created_at' => date('Y-m-d H:i:s'),
            'trace' => Arr::get($record, 'context.exception', [])->getTrace(),
            'all' => json_encode($record),
        ];

        $error_email = Settings::get('error_email');

        Mail::mailer('errors')->to($error_email)->send(new CriticalError($data));
    }
}
