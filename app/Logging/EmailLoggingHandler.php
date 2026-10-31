<?php

namespace App\Logging;

use App\Logging\Concerns\InspectsLogRecords;
use App\Mail\CriticalError;
use App\Support\Facades\Settings;
use Illuminate\Support\Facades\Mail;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class EmailLoggingHandler extends AbstractProcessingHandler
{
    use InspectsLogRecords;

    public function __construct($level = Level::Error, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $error_email = Settings::get('error_email');

        if (! $error_email || $this->isDuplicateAlert($record, 'email')) {
            return;
        }

        $origin = $this->requestOrigin();

        $data = [
            'message' => $record->message,
            'organization_id' => $this->recordOrganizationId($record),
            'context' => $this->recordContext($record),
            'level' => $record->level->value,
            'level_name' => $record->level->getName(),
            'channel' => $record->channel,
            'record_datetime' => $record->datetime->format('Y-m-d H:i:s'),
            'extra' => $record->extra,
            'formatted' => (string) $record->formatted,
            'remote_addr' => $origin['remote_addr'],
            'user_agent' => $origin['user_agent'],
            'created_at' => date('Y-m-d H:i:s'),
            'trace' => $this->recordTrace($record),
        ];

        Mail::mailer('errors')->to($error_email)->send(new CriticalError($data));
    }
}
