<?php

use App\Logging\EmailCustomLogger;
use App\Logging\EmailLoggingHandler;
use App\Logging\MatrixLogger;
use App\Logging\MatrixLoggingHandler;
use App\Logging\MySQLCustomLogger;
use App\Logging\MySQLLoggingHandler;
use App\Logging\Processors\StructuredLogProcessor;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Handler\SyslogUdpHandler;

return [
    /*
    |--------------------------------------------------------------------------
    | Default Log Channel
    |--------------------------------------------------------------------------
    |
    | This option defines the default log channel that gets used when writing
    | messages to the logs. The name specified in this option should match
    | one of the channels defined in the "channels" configuration array.
    |
    */

    'default' => env('LOG_CHANNEL', 'custom'),

    /*
    |--------------------------------------------------------------------------
    | Log record metadata
    |--------------------------------------------------------------------------
    |
    | Stamped on every record written through the "stderr_json" channel so a log
    | collector can tell the web, queue worker and scheduler workloads apart.
    | Set LOG_PROCESS to "web", "queue" or "scheduler" on each deployment;
    | when empty it falls back to "web" for HTTP requests and "cli" otherwise.
    |
    */

    'process' => env('LOG_PROCESS'),

    'release' => env('APP_RELEASE'),

    // Log a heartbeat line every minute from the scheduler (see routes/console.php).
    'heartbeat' => (bool) env('LOG_HEARTBEAT', false),

    // Days to keep rows in the "logs" table (pruned daily by the scheduler).
    'db_days' => (int) env('LOG_DB_DAYS', 90),

    /*
    |--------------------------------------------------------------------------
    | Log Channels
    |--------------------------------------------------------------------------
    |
    | Here you may configure the log channels for your application. Out of
    | the box, Laravel uses the Monolog PHP logging library. This gives
    | you a variety of powerful log handlers / formatters to utilize.
    |
    | Available Drivers: "single", "daily", "slack", "syslog",
    |                    "errorlog", "monolog",
    |                    "custom", "stack"
    |
    */

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => [
                'daily',
                'errorlog',
                'emergency',
                'mysql',
                'matrix',
                'email',
            ],
            'ignore_exceptions' => true,
        ],

        // The default channel. LOG_STACK picks its members, e.g. in a container:
        // LOG_STACK=stderr_json,mysql,email
        'custom' => [
            'driver' => 'stack',
            'channels' => array_values(array_filter(array_map('trim', explode(',', (string) env('LOG_STACK', 'mysql,email,daily'))))),
            'ignore_exceptions' => true,
        ],

        // One JSON object per line on stderr, for Docker / Kubernetes log collectors (Alloy, Promtail, ...).
        // Uses stderr rather than stdout: output on stdout can corrupt FrankenPHP's worker handshake.
        'stderr_json' => [
            'driver' => 'monolog',
            'level' => env('LOG_LEVEL', 'info'),
            'handler' => StreamHandler::class,
            'handler_with' => [
                'stream' => 'php://stderr',
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'appendNewline' => true,
                'includeStacktraces' => true,
            ],
            'processors' => [
                StructuredLogProcessor::class,
            ],
        ],

        'single' => [
            'driver' => 'single',
            'path' => storage_path('logs/laravel.log'),
            'level' => 'debug',
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => (int) env('LOG_DAILY_DAYS', 14),
        ],

        'slack' => [
            'driver' => 'slack',
            'url' => env('LOG_SLACK_WEBHOOK_URL'),
            'username' => 'Laravel Log',
            'emoji' => ':boom:',
            'level' => 'critical',
        ],

        'papertrail' => [
            'driver' => 'monolog',
            'level' => 'debug',
            'handler' => SyslogUdpHandler::class,
            'handler_with' => [
                'host' => env('PAPERTRAIL_URL'),
                'port' => env('PAPERTRAIL_PORT'),
            ],
        ],

        'stderr' => [
            'driver' => 'monolog',
            'handler' => StreamHandler::class,
            'formatter' => env('LOG_STDERR_FORMATTER'),
            'with' => [
                'stream' => 'php://stderr',
            ],
        ],

        'syslog' => [
            'driver' => 'syslog',
            'level' => 'debug',
        ],

        // The errorlog driver writes through PHP's error_log(), so it takes no path.
        'errorlog' => [
            'driver' => 'errorlog',
            'level' => 'error',
        ],

        'null' => [
            'driver' => 'monolog',
            'handler' => NullHandler::class,
        ],

        'emergency' => [
            'driver' => 'errorlog',
            'level' => 'emergency',
        ],

        // Log to MySQL
        'mysql' => [
            'driver' => 'custom',
            'handler' => MySQLLoggingHandler::class,
            'via' => MySQLCustomLogger::class,
            'level' => env('LOG_DB_LEVEL', 'info'),
        ],

        // Email critical alerts
        'email' => [
            'driver' => 'custom',
            'handler' => EmailLoggingHandler::class,
            'via' => EmailCustomLogger::class,
            'level' => 'error',
        ],

        // Log to Matrix
        'matrix' => [
            'driver' => 'custom',
            'handler' => MatrixLoggingHandler::class,
            'via' => MatrixLogger::class,
            'level' => 'error',
        ],
    ],
];
