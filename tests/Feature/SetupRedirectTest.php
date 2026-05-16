<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

it('redirects to setup from login when app is not installed', function () {
    $this->get('/login')->assertRedirect('/setup');
});

it('redirects to setup from dashboard when app is not installed', function () {
    $this->get('/welcome')->assertRedirect('/setup');
});

it('redirects to setup from admin pages when app is not installed', function () {
    $this->get('/admin/service/announcements')->assertRedirect('/setup');
});
