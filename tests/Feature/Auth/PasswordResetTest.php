<?php

use Tests\Support\TestSupports;

it('requires email to request password reset', function () {
    (new TestSupports)->seed();

    $response = $this->post('/password/reset', ['email' => '']);

    $response->assertSessionHasErrors('email');
});
