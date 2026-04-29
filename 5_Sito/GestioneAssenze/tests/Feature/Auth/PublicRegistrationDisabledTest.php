<?php

namespace Tests\Feature\Auth;

use Tests\TestCase;

class PublicRegistrationDisabledTest extends TestCase
{
    public function test_register_routes_are_not_available(): void
    {
        $this->get('/register')->assertNotFound();

        $this->post('/register', [
            'name' => 'Mario',
            'surname' => 'Rossi',
            'email' => 'mario.rossi@example.test',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertNotFound();
    }
}
