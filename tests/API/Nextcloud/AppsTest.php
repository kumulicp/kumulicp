<?php

namespace Tests\Feature\Nextcloud;

use App\AppInstance;
use App\Integrations\Applications\Nextcloud\API\Apps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TestSupports;
use Tests\TestCase;

class AppsTest extends TestCase
{
    use RefreshDatabase;

    public function test_nextcloud_apps_api()
    {
        $support = new TestSupports;
        $support->seed();
        $nextcloud = AppInstance::where('name', 'nextcloud')->first();

        // https://demo-nextcloud.example.com/ocs/v1.php/cloud/apps/contacts

        $apps = new Apps($nextcloud);

        // Enable
        $enable = $apps->enable('contacts');
        $this->assertTrue($apps->isEnabled('contacts'));

        // Disable
        $disable = $apps->disable('contacts');
        $this->assertFalse($apps->isEnabled('contacts'));

        // Find
        $find = $apps->find('contacts');
        $this->assertEquals('contacts', $apps->data->id);
    }
}
