<?php

namespace App\Logging;

use Illuminate\Support\Facades\Http;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class MatrixLoggingHandler extends AbstractProcessingHandler
{
    /**
     * Reference:
     * https://github.com/markhilton/monolog-mysql/blob/master/src/Logger/Monolog/Handler/MysqlHandler.php
     */
    public function __construct($level = Level::Error, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $homeserver = config('services.matrix.homeserver');
        $access_token = config('services.matrix.access_token');
        $room_id = config('services.matrix.room_id');
        $txn_id = 'm'.now()->timestamp;

        $message = $this->formatMessage($record);

        Http::withToken($access_token)->put("{$homeserver}/_matrix/client/r0/rooms/{$room_id}/send/m.room.message/{$txn_id}",
            [
                'msgtype' => 'm.text',
                'body' => $message,
            ],
        );
    }

    protected function formatMessage(LogRecord $record): string
    {
        return sprintf(
            "[%s] %s: %s\n%s",
            $record['datetime']->format('Y-m-d H:i:s'),
            $record['level_name'],
            $record['message'],
            isset($record['context']['exception'])
                ? $record['context']['exception']->getTraceAsString()
                : json_encode($record['context']),
        );
    }
}
