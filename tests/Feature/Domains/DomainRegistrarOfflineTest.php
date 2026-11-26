<?php

use App\OrgDomain;
use App\Tld;
use App\User;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Http;
use OffloadProject\Toggle\Facades\Toggle;
use Tests\Support\TestSupports;

beforeEach(function () {
    $support = new TestSupports;
    $support->seed();
    Toggle::delete('domain-registrar');
    config(['toggle.flags.domain-registrar' => true]);
    Cache::flush();
    $support->createBase2Plan();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->actingAs($this->user);
    $support->setSubscription($this->user->organization, $support->base_2);

    $this->tld = Tld::create([
        'name' => 'com',
        'default_driver' => 'namecheap',
        'is_api_registerable' => true,
        'is_api_renewable' => true,
        'is_api_transferable' => true,
    ]);

    $this->domain = OrgDomain::factory()->create([
        'organization_id' => $this->user->organization->id,
        'name' => 'lollipopsandrainbows.com',
        'type' => 'managed',
        'source' => 'namecheap',
        'status' => 'active',
        'tld_id' => $this->tld->id,
        'expires_at' => now()->addMonths(6),
    ]);

    // Namecheap can't be reached
    $this->attempts = 0;
    Http::fake(function () {
        $this->attempts++;

        throw new ConnectionException('cURL error 28: Resolving timed out after 10000 milliseconds');
    });
});

function storePrices(Tld $tld, bool $stale): void
{
    $prices = json_encode([1 => 10.5, 2 => 21.0, 3 => 31.5, 4 => 42.0, 5 => 52.5]);

    DB::table('tlds')->where('id', $tld->id)->update([
        'register_price' => 10.5,
        'register_prices' => $prices,
        'transfer_price' => 9.5,
        'transfer_prices' => $prices,
        'renew_price' => 10.5,
        'renew_prices' => $prices,
        'reactivate_price' => 80,
        'updated_at' => $stale ? now()->subDays(30) : now(),
    ]);
}

it('opens the domain page with the stored prices when the registrar cannot be reached', function () {
    storePrices($this->tld, stale: true);

    $this->get('/settings/domains/lollipopsandrainbows.com')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('Organization/Settings/WebDomains/WebDomainsSettings')
            ->where('domain.registrar_offline', true)
            ->where('domain.actions.renew', false)
            ->where('domain.actions.transfer_in', false)
            ->has('domain.renewal_price')
        );
});

it('opens the domain page without prices when the registrar cannot be reached and none are stored', function () {
    $this->get('/settings/domains/lollipopsandrainbows.com')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('domain.registrar_offline', true)
            ->where('domain.actions.renew', false)
            ->where('domain.renewal_price', [])
        );
});

it('does not ask the registrar again on every page load while it is unreachable', function () {
    storePrices($this->tld, stale: true);

    $this->get('/settings/domains/lollipopsandrainbows.com')->assertOk();
    $this->get('/settings/domains/lollipopsandrainbows.com')->assertOk();

    expect($this->attempts)->toBe(1);
});

it('does not call the registrar to show the page when the stored prices are fresh', function () {
    storePrices($this->tld, stale: false);

    $this->get('/settings/domains/lollipopsandrainbows.com')
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('domain.registrar_offline', false)
            ->where('domain.renewal_price.0.year', 1)
        );

    Http::assertNothingSent();
});

it('never runs an availability check just to show renewal prices', function () {
    storePrices($this->tld, stale: true);

    $this->get('/settings/domains/lollipopsandrainbows.com')->assertOk();

    Http::assertNotSent(fn (Request $request) => str_contains((string) $request->body(), 'namecheap.domains.check'));
});

it('shows a message instead of failing when a renewal cannot reach the registrar', function () {
    storePrices($this->tld, stale: false);
    Gate::before(fn () => true);

    $this->post('/settings/domains/lollipopsandrainbows.com/renew', ['years' => 1])
        ->assertRedirect('/settings/domains/lollipopsandrainbows.com')
        ->assertSessionHas('error', __('organization.domain.denied.registrar_unavailable'));
});

it('offers to reactivate a deactivated managed domain, unless the registrar is unreachable', function () {
    setupBillingDriver('fake');
    $this->domain->forceFill(['status' => 'deactivated', 'transfer_id' => 5])->save();

    storePrices($this->tld, stale: false);
    $this->get('/settings/domains/lollipopsandrainbows.com')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('domain.actions.reactivate', true));

    storePrices($this->tld, stale: true);
    $this->get('/settings/domains/lollipopsandrainbows.com')
        ->assertOk()
        ->assertInertia(fn ($page) => $page->where('domain.actions.reactivate', false));
});
