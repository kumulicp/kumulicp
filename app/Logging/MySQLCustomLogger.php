<?php

namespace App\Logging;

use Monolog\Logger;

class MySQLCustomLogger
{
    /**
     * Create a custom Monolog instance.
     *
     *
     * @return Logger
     */
    public function __invoke(array $config)
    {
        $logger = new Logger('MySQLLoggingHandler');

        return $logger->pushHandler(new MySQLLoggingHandler);
    }
}
