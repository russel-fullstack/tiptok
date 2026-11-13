<?php

test('unauthenticated api requests return clean json 401 without redirect', function () {
    $response = $this->get('/api/user', [
        'Accept' => 'application/json',
    ]);
    $response->assertStatus(401)
        ->assertJson([
            'message' => 'Unauthenticated.',
        ]);
});
