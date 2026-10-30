<?php

use App\Organization;
use App\RepoSecret;
use App\Server;
use App\Support\Encryption\EncryptedStores;
use App\Support\Encryption\KeyRing;
use App\Support\Encryption\KeyScanner;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

function newEncryptionKey(): string
{
    return 'base64:'.base64_encode(random_bytes(32));
}

// Swaps APP_KEY / APP_PREVIOUS_KEYS the way a redeploy would: the encrypter is
// rebuilt from config, so models and Crypt see the new keys.
function useEncryptionKeys(string $current, array $previous = []): void
{
    config(['app.key' => $current, 'app.previous_keys' => $previous]);

    app()->forgetInstance('encrypter');
    Crypt::clearResolvedInstance('encrypter');
}

// Writes one row per kind of storage using whatever key is currently active.
function seedEncryptedRows(): array
{
    return [
        'organization' => Organization::factory()->create([
            'secretpw' => 'org-secret',
            'settings' => [
                'sso' => ['secret' => Crypt::encryptString('nested-secret')],
                'note' => 'not a secret',
            ],
        ]),
        'server' => Server::factory()->create(['api_key' => 'server-key', 'api_secret' => 'server-secret']),
        'repo_secret' => RepoSecret::factory()->create(['password' => 'registry-password']),
    ];
}

function rawEncryptedValues(): array
{
    return [
        'secretpw' => DB::table('organizations')->value('secretpw'),
        'settings' => DB::table('organizations')->value('settings'),
        'api_key' => DB::table('servers')->value('api_key'),
        'api_secret' => DB::table('servers')->value('api_secret'),
        'password' => DB::table('repo_secrets')->value('password'),
    ];
}

function expectSeededRowsReadable(array $rows): void
{
    expect($rows['organization']->fresh()->secretpw)->toBe('org-secret');
    expect(Crypt::decryptString($rows['organization']->fresh()->settings['sso']['secret']))->toBe('nested-secret');
    expect($rows['organization']->fresh()->settings['note'])->toBe('not a secret');
    expect($rows['server']->fresh()->api_key)->toBe('server-key');
    expect($rows['server']->fresh()->api_secret)->toBe('server-secret');
    expect($rows['repo_secret']->fresh()->password)->toBe('registry-password');
}

it('tells payloads apart from plaintext', function () {
    expect(KeyRing::isPayload(Crypt::encryptString('secret')))->toBeTrue();
    expect(KeyRing::isPayload(Crypt::encrypt(['serialized'])))->toBeTrue();
    expect(KeyRing::isPayload('secret'))->toBeFalse();
    expect(KeyRing::isPayload(base64_encode(str_repeat('not json', 12))))->toBeFalse();
    expect(KeyRing::isPayload(null))->toBeFalse();
    expect(KeyRing::isPayload(['array']))->toBeFalse();
});

it('classifies a value by the key that reads it', function () {
    $old = newEncryptionKey();
    $new = newEncryptionKey();
    $unrelated = newEncryptionKey();

    useEncryptionKeys($old);
    $payload = Crypt::encryptString('secret');

    $ring = new KeyRing($new, [$old], config('app.cipher'));

    expect($ring->inspect($payload)['status'])->toBe(KeyRing::PREVIOUS);
    expect($ring->inspect($ring->reencrypt($payload))['status'])->toBe(KeyRing::CURRENT);
    expect($ring->inspect('plain value')['status'])->toBe(KeyRing::PLAINTEXT);
    expect((new KeyRing($unrelated, [], config('app.cipher')))->inspect($payload)['status'])->toBe(KeyRing::UNDECRYPTABLE);

    expect($ring->reencrypt($ring->reencrypt($payload)))->toBeNull();
    expect($ring->reencrypt('plain value'))->toBeNull();
});

it('keeps serialized and string payloads readable by their original helpers', function () {
    $old = newEncryptionKey();
    $new = newEncryptionKey();

    useEncryptionKeys($old);
    $serialized = Crypt::encrypt('serialized value');
    $string = Crypt::encryptString('string value');

    $ring = new KeyRing($new, [$old], config('app.cipher'));
    $rotated_serialized = $ring->reencrypt($serialized);
    $rotated_string = $ring->reencrypt($string);

    useEncryptionKeys($new);

    expect(Crypt::decrypt($rotated_serialized))->toBe('serialized value');
    expect(Crypt::decryptString($rotated_string))->toBe('string value');
});

it('reads data written under a previous key once it is listed in previous_keys', function () {
    $old = newEncryptionKey();
    $new = newEncryptionKey();

    useEncryptionKeys($old);
    $rows = seedEncryptedRows();

    useEncryptionKeys($new, [$old]);

    expectSeededRowsReadable($rows);
});

it('re-encrypts every location with the current key and then passes keys:check', function () {
    $old = newEncryptionKey();
    $new = newEncryptionKey();

    useEncryptionKeys($old);
    $rows = seedEncryptedRows();
    $before = rawEncryptedValues();

    useEncryptionKeys($new, [$old]);

    $this->artisan('keys:check')->assertFailed();
    $this->artisan('keys:rotate')->assertSuccessful();

    $after = rawEncryptedValues();
    foreach ($before as $column => $value) {
        expect($after[$column])->not->toBe($value);
    }

    $this->artisan('keys:check')->assertSuccessful();

    // The old key can now be dropped.
    useEncryptionKeys($new);

    $this->artisan('keys:check')->assertSuccessful();
    expectSeededRowsReadable($rows);
});

it('does not write anything on a dry run', function () {
    $old = newEncryptionKey();

    useEncryptionKeys($old);
    seedEncryptedRows();
    $before = rawEncryptedValues();

    useEncryptionKeys(newEncryptionKey(), [$old]);

    $this->artisan('keys:rotate --dry-run')->assertSuccessful();

    expect(rawEncryptedValues())->toBe($before);
});

it('can be run again without rotating anything twice', function () {
    $old = newEncryptionKey();

    useEncryptionKeys($old);
    seedEncryptedRows();

    useEncryptionKeys(newEncryptionKey(), [$old]);

    $first = (new KeyScanner(KeyRing::fromConfig()))->scan(true);
    $second = (new KeyScanner(KeyRing::fromConfig()))->scan(true);

    expect(array_sum(array_column($first, 'rotated')))->toBe(5);
    expect(array_sum(array_column($second, 'rotated')))->toBe(0);
});

it('leaves values it cannot decrypt untouched and fails', function () {
    useEncryptionKeys(newEncryptionKey());
    seedEncryptedRows();
    $before = rawEncryptedValues();

    // The key that wrote the data is in neither APP_KEY nor APP_PREVIOUS_KEYS.
    useEncryptionKeys(newEncryptionKey());

    $this->artisan('keys:rotate')->assertFailed();
    $this->artisan('keys:check')->assertFailed();

    expect(rawEncryptedValues())->toBe($before);
});

it('only fails keys:check on legacy plaintext when strict', function () {
    useEncryptionKeys(newEncryptionKey());
    $server = Server::factory()->create();
    DB::table('servers')->where('id', $server->id)->update(['api_key' => 'legacy-plaintext']);

    $this->artisan('keys:check')->assertSuccessful();
    $this->artisan('keys:check --strict')->assertFailed();
});

it('points every inventoried location at a real column', function () {
    foreach (array_merge(EncryptedStores::columns(), EncryptedStores::jsonColumns()) as $store) {
        expect(Schema::hasColumn($store['table'], $store['column']))
            ->toBeTrue("{$store['table']}.{$store['column']} does not exist");
    }
});

it('registers every file that encrypts data in EncryptedStores', function () {
    $pattern = '/Crypt::|Encrypter|=> \'encrypted\'|Encrypted::class|SecretValue::(encrypt|decrypt)/';

    $found = collect(File::allFiles(app_path()))
        ->filter(fn ($file) => $file->getExtension() === 'php' && preg_match($pattern, $file->getContents()))
        ->map(fn ($file) => str_replace(DIRECTORY_SEPARATOR, '/', $file->getRelativePathname()))
        ->sort()
        ->values()
        ->all();

    $registered = collect(EncryptedStores::sources())->sort()->values()->all();

    expect($found)->toBe($registered, 'A file that encrypts data is not registered in EncryptedStores::sources() (and its columns in columns()/jsonColumns()), so keys:rotate would miss it.');
});
