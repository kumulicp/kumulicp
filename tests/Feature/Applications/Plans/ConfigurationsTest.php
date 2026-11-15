<?php

use App\Application;
use App\AppPlan;
use App\Support\Facades\Application as ApplicationFacade;
use App\User;
use Tests\Support\TestSupports;

beforeEach(function () {
    (new TestSupports)->seed();
    (new TestSupports)->activateDemoApp();

    $this->user = User::where('username', 'demo')->firstOrFail();
    $this->demoApp = Application::where('slug', 'demo_app')->first();
    $this->plan = AppPlan::where('application_id', $this->demoApp->id)->firstOrFail();
});

it('shows the default for a config the plan has never saved', function () {
    $this->plan->settings = array_merge($this->plan->settings ?? [], ['configurations' => []]);
    $this->plan->save();

    $configs = ApplicationFacade::configurations($this->demoApp, $this->plan->fresh(), true);

    expect($configs['non-persistent-value']['value'])->toBe('default-non-persistent');
});

it('keeps a blank config blank instead of filling the default back in', function () {
    $response = $this->actingAs($this->user)->put(
        "/admin/apps/{$this->demoApp->slug}/plans/{$this->plan->id}/configurations",
        ['configurations' => ['non-persistent-value' => null, 'persistent-value' => 'custom']]
    );

    $response->assertRedirect();

    $plan = $this->plan->fresh();
    $configs = ApplicationFacade::configurations($this->demoApp, $plan, true);

    expect($configs['non-persistent-value']['value'])->toBeNull();
    expect($configs['persistent-value']['value'])->toBe('custom');
});
