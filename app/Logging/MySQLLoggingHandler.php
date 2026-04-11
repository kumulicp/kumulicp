<?php

namespace App\Logging;

// use Illuminate\Log\Logger;
use DB;
use Monolog\Handler\AbstractProcessingHandler;
use Monolog\Logger;
use Monolog\LogRecord;

class MySQLLoggingHandler extends AbstractProcessingHandler
{
    private $table = 'logs';

    /**
     * Reference:
     * https://github.com/markhilton/monolog-mysql/blob/master/src/Logger/Monolog/Handler/MysqlHandler.php
     */
    public function __construct($level = Logger::INFO, $bubble = true)
    {
        parent::__construct($level, $bubble);
    }

    protected function write(LogRecord $record): void
    {

        if (in_array('REMOTE_ADDR', $_SERVER, true) && in_array('HTTP_USER_AGENT', $_SERVER, true)) {
            $remote_addr = $_SERVER['REMOTE_ADDR'];
            $http_user_agent = $_SERVER['HTTP_USER_AGENT'];
        } else {
            $remote_addr = '';
            $http_user_agent = '';
        }

        $data = [
            'message' => $record['message'],
            'organization_id' => $record['context']['organization_id'],
            'context' => json_encode($record['context']),
            'level' => $record['level'],
            'level_name' => $record['level_name'],
            'channel' => $record['channel'],
            'record_datetime' => $record['datetime']->format('Y-m-d H:i:s'),
            'extra' => json_encode($record['extra']),
            'formatted' => $record['formatted'],
            'remote_addr' => $remote_addr,
            'user_agent' => $http_user_agent,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        DB::connection()->table($this->table)->insert($data);
    }
}
