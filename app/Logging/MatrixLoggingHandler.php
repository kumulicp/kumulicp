<?php

namespace App\Logging;

use App\Logging\Concerns\InspectsLogRecords;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;
use Throwable;

class MatrixLoggingHandler extends AbstractProcessingHandler
{
    use InspectsLogRecords;

    public function __construct($level = Level::Error, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $homeserver = config('services.matrix.homeserver');
        $access_token = config('services.matrix.access_token');
        $room_id = config('services.matrix.room_id');

        if (! $homeserver || ! $access_token || ! $room_id || $this->isDuplicateAlert($record, 'matrix')) {
            return;
        }

        // Matrix drops a message whose transaction id was already used, so it must be unique per message.
        $txn_id = 'm'.Str::uuid()->toString();

        try {
            Http::withToken($access_token)
                ->timeout(5)
                ->put(
                    rtrim($homeserver, '/')."/_matrix/client/r0/rooms/{$room_id}/send/m.room.message/{$txn_id}",
                    [
                        'msgtype' => 'm.text',
                        'body' => $this->formatMessage($record),
                    ],
                );
        } catch (Throwable) {
            // Alert delivery must never break the request or job that logged the record.
        }
    }

    protected function formatMessage(LogRecord $record): string
    {
        $details = $this->recordTrace($record);

        if ($details === '') {
            $details = mb_substr($this->encodeJson($this->recordContext($record)), 0, self::TRACE_LIMIT);
        }

        return sprintf(
            "[%s] %s: %s\n%s",
            $record->datetime->format('Y-m-d H:i:s'),
            $record->level->getName(),
            $record->message,
            $details,
        );
    }
}
