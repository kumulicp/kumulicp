<?php

use App\Support\SafeErrorMessage;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

function leakyQueryException(): QueryException
{
    $previous = new PDOException("SQLSTATE[42S22]: Column not found: 1054 Unknown column 'db_password' in 'INSERT INTO'");

    return new QueryException(
        'mysql',
        'insert into `app_instances` (`api_password`, `db_password`) values (?, ?)',
        ['eyJpdiI6ImVuY3J5cHRlZCJ9', 'secret-bound-value'],
        $previous,
    );
}

it('replaces a database exception with a generic message', function () {
    $message = SafeErrorMessage::from(leakyQueryException());

    expect($message)->toBe(__('messages.exception.database_error'))
        ->not->toContain('SQLSTATE')
        ->not->toContain('app_instances')
        ->not->toContain('secret-bound-value');
});

it('scrubs stored database error text but leaves other text alone', function () {
    expect(SafeErrorMessage::scrub(leakyQueryException()->getMessage()))->toBe(__('messages.exception.database_error'))
        ->and(SafeErrorMessage::scrub('Rancher is unreachable: timeout'))->toBe('Rancher is unreachable: timeout')
        ->and(SafeErrorMessage::scrub(''))->toBe('')
        ->and(SafeErrorMessage::scrub(null))->toBeNull();
});

it('passes ordinary exception messages through', function () {
    expect(SafeErrorMessage::from(new RuntimeException('No backup file to restore from')))->toBe('No backup file to restore from');
});

it('does not render database errors outside a local or development environment with debug on', function (string $env, bool $debug) {
    app()['env'] = $env;
    config(['app.debug' => $debug]);

    Route::get('/_leak-test', fn () => throw leakyQueryException())->middleware('web');

    $response = $this->get('/_leak-test', ['Accept' => 'application/json']);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->not->toContain('SQLSTATE')->not->toContain('db_password')->not->toContain('control-panel');
})->with([
    'production, debug off' => ['production', false],
    'production, debug on' => ['production', true],
    'local, debug off' => ['local', false],
    'development, debug off' => ['development', false],
]);

it('only sends developer-written messages to the error pages', function () {
    expect(SafeErrorMessage::forErrorPage(leakyQueryException(), 500))->toBeNull()
        ->and(SafeErrorMessage::forErrorPage(new HttpException(503, 'Back soon'), 503))->toBeNull()
        ->and(SafeErrorMessage::forErrorPage(new NotFoundHttpException('No query results for model [App\AppInstance] 5'), 404))->toBeNull()
        ->and(SafeErrorMessage::forErrorPage(new RuntimeException('internal detail'), 403))->toBeNull()
        ->and(SafeErrorMessage::forErrorPage(new AccessDeniedHttpException('Not allowed here'), 403))->toBe('Not allowed here');
});

it('still renders the debug page in a development environment with debug on', function (string $env) {
    app()['env'] = $env;
    config(['app.debug' => true]);

    Route::get('/_debug-test', fn () => throw leakyQueryException())->middleware('web');

    $this->get('/_debug-test', ['Accept' => 'application/json'])
        ->assertStatus(500)
        ->assertSee('SQLSTATE', false);
})->with(['local', 'development']);

it('does not send PHP error messages (parse errors, type errors) to the error pages in production', function (Throwable $error) {
    app()['env'] = 'production';
    config(['app.debug' => false]);

    Route::get('/_php-error-test', fn () => throw $error)->middleware('web');

    $response = $this->get('/_php-error-test', ['Accept' => 'application/json']);

    expect($response->getStatusCode())->toBe(500)
        ->and($response->getContent())->not->toContain('syntax error')->not->toContain('unexpected')->not->toContain('must be of type');
})->with([
    'parse error' => fn () => new ParseError('syntax error, unexpected variable "$o"'),
    'type error' => fn () => new TypeError('foo(): Argument #1 ($x) must be of type int, string given'),
    'error' => fn () => new Error('Call to undefined method App\Foo::bar()'),
]);
