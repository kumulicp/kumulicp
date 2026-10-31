<?php

use App\Integrations\Integration;
use App\Logging\EmailLoggingHandler;
use App\Logging\MatrixLoggingHandler;
use App\Logging\MySQLLoggingHandler;
use App\Mail\CriticalError;
use App\Support\Facades\Settings;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Monolog\Level;
use Monolog\LogRecord;

/*
|--------------------------------------------------------------------------
| Custom Monolog handlers
|--------------------------------------------------------------------------
|
| The email / Matrix / MySQL handlers sit behind `ignore_exceptions`, so a bug
| inside them silently drops alerts instead of failing loudly. These tests call
| write() paths directly to make sure each handler survives the records the app
| really produces (no exception, no organization, console context).
*/

function make_log_record(string $message = 'Something broke', Level $level = Level::Critical, array $context = []): LogRecord
{
    return new LogRecord(new DateTimeImmutable, 'testing', $level, $message, $context);
}

beforeEach(function () {
    Cache::flush();
});

it('emails critical records that have no exception', function () {
    Mail::fake();
    Settings::update('error_email', 'ops@example.com');

    (new EmailLoggingHandler)->handle(make_log_record('Backup failed', context: ['organization_id' => 1]));

    Mail::assertQueued(CriticalError::class, fn (CriticalError $mail) => $mail->log['message'] === 'Backup failed'
        && $mail->log['trace'] === '');
});

it('emails the trimmed trace and no exception object for records with an exception', function () {
    Mail::fake();
    Settings::update('error_email', 'ops@example.com');

    $exception = new RuntimeException('Upstream down');
    (new EmailLoggingHandler)->handle(make_log_record('Task failed', context: ['exception' => $exception]));

    Mail::assertQueued(CriticalError::class, function (CriticalError $mail) {
        return $mail->log['trace'] !== ''
            && $mail->log['context']['exception']['message'] === 'Upstream down'
            && ! is_object($mail->log['context']['exception']);
    });
});

it('does not send the same email alert twice within the dedupe window', function () {
    Mail::fake();
    Settings::update('error_email', 'ops@example.com');

    $handler = new EmailLoggingHandler;
    $handler->handle(make_log_record('Same problem'));
    $handler->handle(make_log_record('Same problem'));
    $handler->handle(make_log_record('Different problem'));

    Mail::assertQueuedCount(2);
});

it('does nothing when no error email is configured', function () {
    Mail::fake();
    Settings::remove('error_email');

    (new EmailLoggingHandler)->handle(make_log_record());

    Mail::assertNothingQueued();
});

it('stores serialised exceptions and masks secrets in the mysql log table', function () {
    $exception = new RuntimeException('Cannot connect');

    (new MySQLLoggingHandler)->handle(make_log_record('Sync failed', Level::Error, [
        'organization_id' => '1',
        'api_token' => 'super-secret',
        'exception' => $exception,
    ]));

    $row = DB::table('logs')->where('message', 'Sync failed')->first();
    $context = json_decode($row->context, true);

    expect($row->organization_id)->toBe(1)
        ->and($row->level_name)->toBe('ERROR')
        ->and($context['api_token'])->not->toBe('super-secret')
        ->and($context['exception']['class'])->toBe(RuntimeException::class)
        ->and($context['exception']['message'])->toBe('Cannot connect');
});

it('stores a null organization when the record has none', function () {
    (new MySQLLoggingHandler)->handle(make_log_record('Cron noise', Level::Info));

    expect(DB::table('logs')->where('message', 'Cron noise')->value('organization_id'))->toBeNull();
});

it('posts matrix alerts with a unique transaction id each time', function () {
    config([
        'services.matrix.homeserver' => 'https://matrix.test',
        'services.matrix.access_token' => 'token',
        'services.matrix.room_id' => '!room:matrix.test',
    ]);
    Http::fake();

    $handler = new MatrixLoggingHandler;
    $handler->handle(make_log_record('First problem'));
    $handler->handle(make_log_record('Second problem'));

    $urls = Http::recorded()->map(fn ($pair) => $pair[0]->url())->all();

    expect($urls)->toHaveCount(2)
        ->and($urls[0])->not->toBe($urls[1]);
});

it('skips matrix alerts when matrix is not configured', function () {
    config([
        'services.matrix.homeserver' => null,
        'services.matrix.access_token' => null,
        'services.matrix.room_id' => null,
    ]);
    Http::fake();

    (new MatrixLoggingHandler)->handle(make_log_record());

    Http::assertNothingSent();
});

it('never throws when matrix is unreachable', function () {
    config([
        'services.matrix.homeserver' => 'https://matrix.test',
        'services.matrix.access_token' => 'token',
        'services.matrix.room_id' => '!room:matrix.test',
    ]);
    Http::fake(fn () => throw new ConnectionException('timeout'));

    (new MatrixLoggingHandler)->handle(make_log_record());

    expect(true)->toBeTrue();
});

it('masks secrets and truncates upstream error bodies', function () {
    $integration = (new ReflectionClass(Integration::class))->newInstanceWithoutConstructor();
    $method = new ReflectionMethod($integration, 'scrubResponseBody');

    $masked = $method->invoke($integration, json_encode(['error' => 'bad', 'client_secret' => 'abc123']));
    $truncated = $method->invoke($integration, str_repeat('x', 5000));

    expect($masked)->not->toContain('abc123')
        ->and($masked)->toContain('bad')
        ->and(mb_strlen($truncated))->toBeLessThan(1100);
});
