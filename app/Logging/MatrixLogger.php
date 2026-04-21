<?php

namespace App\Logging;

use Monolog\Logger;

class MatrixLogger
{
    public function __invoke(array $config)
    {
        $logger = new Logger('MatrixLoggingHandler');

        return $logger->pushHandler(new MatrixLoggingHandler);
    }
}
