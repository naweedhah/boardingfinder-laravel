<?php

namespace Tests;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Auth;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    // `*Json()` helpers only send cookies when credentials are enabled, hence withCredentials().

    /**
     * Authenticates subsequent requests the way the browser does: with the `token` cookie.
     * Pass no user to create a fresh student.
     */
    protected function signIn(?User $user = null): User
    {
        $user ??= User::factory()->create();

        // The token guard caches its user for the life of the app instance; tests reuse one
        // instance across requests, so drop it when switching accounts.
        Auth::forgetGuards();

        $this->withCredentials()->withUnencryptedCookie('token', $user->createToken('test')->plainTextToken);

        return $user;
    }
}
