<?php

it('enforces max domains limit', function () {
    $this->followingRedirects();
    $this->user->organization->domains()->delete();

    $this->support->setSubscription($this->user->organization, $this->support->base_2, $this->support->demo_app_2, $this->demoApp);

    $domain1 = $this->post('/settings/domains/connect', ['domain_name' => 'example1.com']);
    $domain1->assertSee('example1.com');

    $this->followingRedirects();
    $domain2 = $this->post('/settings/domains/connect', ['domain_name' => 'example2.com']);
    $domain2->assertSee('example2.com');

    $domain3 = $this->post('/settings/domains/connect', ['domain_name' => 'example3.com']);
    $domain3->assertStatus(403);
});
