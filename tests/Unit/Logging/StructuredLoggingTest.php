<?php

use App\Http\Middleware\AddRequestContext;
use App\Logging\Processors\StructuredLogProcessor;
use App\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Monolog\Level;
use Monolog\LogRecord;
use Symfony\Component\HttpFoundation\Response;

/*
|--------------------------------------------------------------------------
| Structured (JSON) logging
|--------------------------------------------------------------------------
|
| The stderr_json channel feeds Loki / Alloy. These tests cover what the
| collector relies on: shared context on every record, a stable `event` name,
| masked secrets and the request id round trip.
*/

function structured_record(string $message, array $context = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'testing', Level::Info, $message, $context);
}

it('adds service metadata and shared context to the record extra', function () {
    config(['logging.process' => 'queue', 'logging.release' => '1.2.3']);
    Context::add(['request_id' => 'abc12345', 'task_id' => 7]);

    $record = (new StructuredLogProcessor)(structured_record('Something happened'));

    expect($record->extra)->toMatchArray([
        'process' => 'queue',
        'release' => '1.2.3',
        'request_id' => 'abc12345',
        'task_id' => 7,
    ])->and($record->extra['service'])->toBe(config('app.name'));
});

it('derives a stable event from a dotted message or an explicit event key', function () {
    $dotted = (new StructuredLogProcessor)(structured_record('task.failed'));
    $explicit = (new StructuredLogProcessor)(structured_record('Rancher application created for Acme', ['event' => 'messages.api.rancher.log.app_created']));
    $plain = (new StructuredLogProcessor)(structured_record('A plain sentence.'));

    expect($dotted->extra['event'])->toBe('task.failed')
        ->and($explicit->extra['event'])->toBe('messages.api.rancher.log.app_created')
        ->and($plain->extra)->not->toHaveKey('event');
});

it('masks secret looking keys and truncates long strings', function () {
    $record = (new StructuredLogProcessor)(structured_record('Sync', [
        'api_token' => 'super-secret',
        'nested' => ['client_secret' => 'abc123'],
        'big' => str_repeat('x', 5000),
    ]));

    expect(json_encode($record->context))->not->toContain('super-secret')->not->toContain('abc123')
        ->and(mb_strlen($record->context['big']))->toBeLessThan(2100);
});

it('writes one json object per line through the stderr_json channel', function () {
    $stream = fopen('php://memory', 'w+');
    $handler = new Monolog\Handler\StreamHandler($stream);
    $handler->setFormatter(new Monolog\Formatter\JsonFormatter(Monolog\Formatter\JsonFormatter::BATCH_MODE_JSON, true, false, true));
    $logger = new Monolog\Logger('testing', [$handler], [new StructuredLogProcessor]);

    $logger->info('task.started', ['task_id' => 3]);
    $logger->error('boom', ['exception' => new RuntimeException('bad')]);

    rewind($stream);
    $lines = array_filter(explode("\n", stream_get_contents($stream)));

    expect($lines)->toHaveCount(2);
    $first = json_decode(array_values($lines)[0], true);
    expect($first['message'])->toBe('task.started')->and($first['extra']['event'])->toBe('task.started')
        ->and($first['level_name'])->toBe('INFO');
});

it('defines the stderr_json channel and a configurable default stack', function () {
    expect(config('logging.channels.stderr_json.handler'))->toBe(Monolog\Handler\StreamHandler::class)
        ->and(config('logging.channels.stderr_json.formatter'))->toBe(Monolog\Formatter\JsonFormatter::class)
        ->and(config('logging.channels.custom.channels'))->toContain('mysql');
});

it('generates a request id, reuses a valid incoming one and echoes it back', function () {
    $middleware = new AddRequestContext;
    $next = fn () => new Response;

    $generated = $middleware->handle(Request::create('/anything'), $next);
    $reused = $middleware->handle(Request::create('/anything', server: ['HTTP_X_REQUEST_ID' => 'req-12345678']), $next);
    $rejected = $middleware->handle(Request::create('/anything', server: ['HTTP_X_REQUEST_ID' => "bad id\nwith newline"]), $next);

    expect($generated->headers->get('X-Request-Id'))->toMatch('/^[0-9a-f-]{36}$/')
        ->and($reused->headers->get('X-Request-Id'))->toBe('req-12345678')
        ->and($rejected->headers->get('X-Request-Id'))->not->toContain("\n");
});

it('prunes log rows older than the configured retention', function () {
    config(['logging.db_days' => 30]);

    foreach (['old' => 40, 'recent' => 5] as $message => $days) {
        DB::table('logs')->insert([
            'message' => $message, 'context' => '{}', 'level' => '200', 'level_name' => 'INFO', 'channel' => 'testing',
            'record_datetime' => now()->toDateTimeString(), 'extra' => '{}', 'formatted' => '',
            'created_at' => now()->subDays($days), 'updated_at' => now()->subDays($days),
        ]);
    }

    $this->artisan('model:prune', ['--model' => [Log::class]])->assertSuccessful();

    expect(DB::table('logs')->pluck('message')->all())->toBe(['recent']);
});
