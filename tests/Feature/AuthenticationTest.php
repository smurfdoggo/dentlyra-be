<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, class-string<User>|class-string<Admin>, string}> */
    public static function accountTypes(): array
    {
        return [
            'user' => ['users', User::class, 'admins'],
            'admin' => ['admins', Admin::class, 'users'],
        ];
    }

    #[DataProvider('accountTypes')]
    public function test_accounts_can_log_in_and_access_their_profile(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();

        $response = $this->postJson("/api/v1/{$area}/login", [
            'email' => $account->email,
            'password' => 'password',
            'device_name' => 'test-device',
        ])->assertOk()
            ->assertJsonPath('data.id', $account->id)
            ->assertJsonPath('token_type', 'Bearer')
            ->assertJsonMissingPath('data.password')
            ->assertJsonMissingPath('data.remember_token');

        $token = PersonalAccessToken::findToken($response->json('token'));

        $this->assertTrue($token->tokenable->is($account));
        $this->assertSame('test-device', $token->name);
        $this->assertNotSame($response->json('token'), $token->token);

        $this->withToken($response->json('token'))->getJson("/api/v1/{$area}/me")
            ->assertOk()
            ->assertExactJson(['data' => [
                'id' => $account->id,
                'name' => $account->name,
                'email' => $account->email,
            ]]);
    }

    #[DataProvider('accountTypes')]
    public function test_tokens_cannot_access_the_other_account_type(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();
        $token = $account->createToken('test');

        $this->withToken($token->plainTextToken)->getJson("/api/v1/{$otherArea}/me")->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->postJson("/api/v1/{$otherArea}/logout")->assertUnauthorized();
        $this->assertModelExists($token->accessToken);
    }

    #[DataProvider('accountTypes')]
    public function test_credentials_cannot_log_in_to_the_other_account_type(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();

        $this->postJson("/api/v1/{$otherArea}/login", [
            'email' => $account->email,
            'password' => 'password',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('accountTypes')]
    public function test_invalid_credentials_do_not_issue_tokens(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();

        $wrongPassword = $this->postJson("/api/v1/{$area}/login", [
            'email' => $account->email,
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $unknownAccount = $this->postJson("/api/v1/{$area}/login", [
            'email' => 'missing@example.com',
            'password' => 'incorrect',
        ])->assertUnprocessable()->assertJsonValidationErrors('email');

        $this->assertSame($wrongPassword->json(), $unknownAccount->json());
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('accountTypes')]
    public function test_login_validates_input(string $area, string $model, string $otherArea): void
    {
        $this->postJson("/api/v1/{$area}/login", [
            'email' => ['invalid'],
            'password' => [],
            'device_name' => str_repeat('x', 256),
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password', 'device_name']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    #[DataProvider('accountTypes')]
    public function test_protected_endpoints_require_a_valid_token_even_without_accept_header(string $area, string $model, string $otherArea): void
    {
        $this->get("/api/v1/{$area}/me")->assertUnauthorized()->assertJsonPath('message', 'Unauthenticated.');
        $this->post("/api/v1/{$area}/logout")->assertUnauthorized();
        $this->withToken('invalid-token')->getJson("/api/v1/{$area}/me")->assertUnauthorized();
    }

    #[DataProvider('accountTypes')]
    public function test_logout_revokes_only_the_current_token(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();
        $currentToken = $account->createToken('current');
        $otherToken = $account->createToken('other');

        $this->withToken($currentToken->plainTextToken)->postJson("/api/v1/{$area}/logout")->assertNoContent();

        $this->assertModelMissing($currentToken->accessToken);
        $this->assertModelExists($otherToken->accessToken);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/v1/{$area}/me")->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken->plainTextToken)->getJson("/api/v1/{$area}/me")->assertOk();
    }

    #[DataProvider('accountTypes')]
    public function test_expired_tokens_cannot_authenticate(string $area, string $model, string $otherArea): void
    {
        $account = $model::factory()->create();
        $token = $account->createToken('expired', ['*'], now()->subMinute());

        $this->withToken($token->plainTextToken)->getJson("/api/v1/{$area}/me")->assertUnauthorized();

        $this->app['auth']->forgetGuards();
        $token = $account->createToken('old');
        $token->accessToken->forceFill(['created_at' => now()->subMinutes(config('sanctum.expiration') + 1)])->save();

        $this->withToken($token->plainTextToken)->getJson("/api/v1/{$area}/me")->assertUnauthorized();
    }

    #[DataProvider('accountTypes')]
    public function test_login_is_rate_limited(string $area, string $model, string $otherArea): void
    {
        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson("/api/v1/{$area}/login", [
                'email' => 'missing@example.com',
                'password' => 'incorrect',
            ])->assertUnprocessable();
        }

        $this->postJson("/api/v1/{$area}/login", [
            'email' => 'missing@example.com',
            'password' => 'incorrect',
        ])->assertTooManyRequests()->assertHeader('Retry-After');
    }

    public function test_users_and_admins_can_share_an_email_with_independent_credentials(): void
    {
        $user = User::factory()->create(['email' => 'shared@example.com', 'password' => 'user-password']);
        $admin = Admin::factory()->create(['email' => 'shared@example.com', 'password' => 'admin-password']);

        $this->postJson('/api/v1/users/login', ['email' => $user->email, 'password' => 'admin-password'])
            ->assertUnprocessable();
        $this->postJson('/api/v1/admins/login', ['email' => $admin->email, 'password' => 'user-password'])
            ->assertUnprocessable();

        $userLogin = $this->postJson('/api/v1/users/login', ['email' => $user->email, 'password' => 'user-password'])->assertOk();
        $adminLogin = $this->postJson('/api/v1/admins/login', ['email' => $admin->email, 'password' => 'admin-password'])->assertOk();

        $this->assertInstanceOf(User::class, PersonalAccessToken::findToken($userLogin->json('token'))->tokenable);
        $this->assertInstanceOf(Admin::class, PersonalAccessToken::findToken($adminLogin->json('token'))->tokenable);
    }

    public function test_registration_creates_only_a_regular_user_and_returns_a_working_token(): void
    {
        $response = $this->postJson('/api/v1/users/register', [
            'name' => 'New User',
            'email' => 'new@example.com',
            'password' => 'safe-password',
            'password_confirmation' => 'safe-password',
            'is_admin' => true,
            'role' => 'admin',
        ])->assertCreated()->assertJsonPath('data.email', 'new@example.com')->assertJsonMissingPath('data.password');

        $this->assertDatabaseCount('admins', 0);
        $user = User::query()->sole();
        $this->assertTrue(Hash::check('safe-password', $user->password));

        $this->withToken($response->json('token'))->getJson('/api/v1/users/me')->assertOk();
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/admins/me')->assertUnauthorized();
    }

    public function test_registration_validates_required_fields_unique_email_and_password_confirmation(): void
    {
        $user = User::factory()->create();

        $this->postJson('/api/v1/users/register', [])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'password']);

        $this->postJson('/api/v1/users/register', [
            'name' => 'Duplicate User',
            'email' => $user->email,
            'password' => 'short',
            'password_confirmation' => 'different',
        ])->assertUnprocessable()->assertJsonValidationErrors(['email', 'password']);

        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_admin_registration_is_not_publicly_available(): void
    {
        $this->postJson('/api/v1/admins/register', [
            'name' => 'Attacker',
            'email' => 'attacker@example.com',
            'password' => 'password',
        ])->assertNotFound();

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_web_sessions_do_not_authenticate_token_endpoints(): void
    {
        $this->actingAs(User::factory()->create(), 'web');

        $this->getJson('/api/v1/users/me')->assertUnauthorized();
        $this->getJson('/api/v1/admins/me')->assertUnauthorized();
    }
}
