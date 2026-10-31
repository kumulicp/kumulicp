<?php

namespace App\Logging;

use App\Logging\Concerns\InspectsLogRecords;
use Illuminate\Support\Facades\DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Level;
use Monolog\LogRecord;

class MySQLLoggingHandler extends AbstractProcessingHandler
{
    use InspectsLogRecords;

    private $table = 'logs';

    public function __construct($level = Level::Info, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {
        $origin = $this->requestOrigin();

        $data = [
            'message' => $record->message,
            'organization_id' => $this->recordOrganizationId($record),
            'context' => $this->encodeJson($this->recordContext($record)),
            'level' => $record->level->value,
            'level_name' => $record->level->getName(),
            'channel' => $record->channel,
            'record_datetime' => $record->datetime->format('Y-m-d H:i:s'),
            'extra' => $this->encodeJson($record->extra),
            'formatted' => (string) $record->formatted,
            'remote_addr' => $origin['remote_addr'],
            'user_agent' => $origin['user_agent'],
            'created_at' => date('Y-m-d H:i:s'),
        ];

        DB::connection()->table($this->table)->insert($data);
    }
}
