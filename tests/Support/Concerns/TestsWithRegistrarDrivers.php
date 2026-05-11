<?php

namespace Tests\Support\Concerns;

use App\Support\Facades\Domain;
use Tests\Support\Registrars\FakeRegistrar;

/**
 * PHPUnit class-style companion to setupRegistrarDriver() in Pest.php.
 *
 * Usage:
 *   use Tests\Support\Concerns\TestsWithRegistrarDrivers;
 *
 *   protected function setUp(): void
 *   {
 *       parent::setUp();
 *       $this->setupRegistrarDriver('fake');
 *   }
 *
 *   /** @dataProvider registrarDriverProvider *\/
 *   public function test_registers_domain(string $driver): void
 *   {
 *       $this->setupRegistrarDriver($driver);
 *       // ...
 *   }
 */
trait TestsWithRegistrarDrivers
{
    protected function setupRegistrarDriver(string $driver): void
    {
        if ($driver === 'fake') {
            Domain::register('fake', FakeRegistrar::class);
        }
    }

    protected function skipIfNotRegistrar(string $required): void
    {
        $driver = env('REGISTRAR_DRIVER', 'fake');
        if ($driver !== $required) {
            $this->markTestSkipped("Requires '{$required}' registrar (REGISTRAR_DRIVER={$required})");
        }
    }

    public static function registrarDriverProvider(): array
    {
        return [['fake'], ['namecheap']];
    }
}
