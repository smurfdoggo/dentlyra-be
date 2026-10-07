<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Timebox;
use Illuminate\Validation\ValidationException;

class AuthService
{
    /** @param array{email: string, password: string} $credentials */
    public function authenticate(string $provider, #[\SensitiveParameter] array $credentials): User|Admin
    {
        return (new Timebox)->call(function (Timebox $timebox) use ($provider, $credentials): User|Admin {
            $userProvider = Auth::createUserProvider($provider);
            $account = $userProvider->retrieveByCredentials($credentials);

            if (! $account || ! $userProvider->validateCredentials($account, $credentials)) {
                throw ValidationException::withMessages([
                    'email' => [__('auth.failed')],
                ]);
            }

            $userProvider->rehashPasswordIfRequired($account, $credentials);
            $timebox->returnEarly();

            return $account;
        }, 200000);
    }
}
