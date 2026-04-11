<?php

namespace App\Logging;

use Monolog\Logger;

class EmailCustomLogger
{
    /**
     * Create a custom Monolog instance.
     *
     *
     * @return Logger
     */
    public function __invoke(array $config)
    {
        $logger = new Logger('EmailLoggingHandler');

        return $logger->pushHandler(new EmailLoggingHandler);
    }
}
