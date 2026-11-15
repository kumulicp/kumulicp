<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Tests\Support\TestSupports;

class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /** @var array<int, string> The demo app's groups in LDAP before the test */
    private array $demoAppGroupsBefore = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->demoAppGroupsBefore = (new TestSupports)->demoAppGroups();
    }

    protected function tearDown(): void
    {
        // Tests that activate the demo app leave its groups in the real LDAP: take the ones this test made
        (new TestSupports)->cleanDemoAppGroups($this->demoAppGroupsBefore);

        parent::tearDown();
    }
}
