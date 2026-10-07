<?php

namespace Tests\Feature;

use App\Models\Admin;
use App\Models\User;
use Database\Seeders\AdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use LogicException;
use Tests\TestCase;

class CreateAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_an_admin_with_a_hashed_password_in_a_separate_table(): void
    {
        User::factory()->create(['email' => 'admin@example.com']);

        $this->artisan('admin:create')
            ->expectsQuestion('Name', 'Clinic Admin')
            ->expectsQuestion('Email', 'admin@example.com')
            ->expectsQuestion('Password', 'StrongPassword123!')
            ->expectsQuestion('Confirm password', 'StrongPassword123!')
            ->expectsOutput('Administrator created successfully.')
            ->assertSuccessful();

        $admin = Admin::query()->sole();

        $this->assertSame('Clinic Admin', $admin->name);
        $this->assertTrue(Hash::check('StrongPassword123!', $admin->password));
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_command_rejects_duplicate_admin_emails_without_changing_existing_passwords(): void
    {
        $admin = Admin::factory()->create(['email' => 'admin@example.com']);
        $password = $admin->password;

        $this->artisan('admin:create', ['--name' => 'Another Admin', '--email' => $admin->email])
            ->expectsQuestion('Password', 'StrongPassword123!')
            ->expectsQuestion('Confirm password', 'StrongPassword123!')
            ->assertFailed();

        $this->assertDatabaseCount('admins', 1);
        $this->assertSame($password, $admin->fresh()->password);
    }

    public function test_command_rejects_weak_or_unconfirmed_passwords(): void
    {
        $this->artisan('admin:create', ['--name' => 'Admin', '--email' => 'admin@example.com'])
            ->expectsQuestion('Password', 'weak')
            ->expectsQuestion('Confirm password', 'different')
            ->assertFailed();

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_command_does_not_create_an_account_non_interactively(): void
    {
        $this->artisan('admin:create', ['--no-interaction' => true])
            ->expectsOutput('Run this command interactively to securely enter the administrator password.')
            ->assertFailed();

        $this->assertDatabaseCount('admins', 0);
    }

    public function test_development_seeder_does_not_overwrite_existing_admin_credentials(): void
    {
        $admin = Admin::factory()->create(['email' => 'admin@example.com']);
        $password = $admin->password;

        $this->seed(AdminSeeder::class);

        $this->assertDatabaseCount('admins', 1);
        $this->assertSame($password, $admin->fresh()->password);
    }

    public function test_development_seeder_cannot_create_default_credentials_in_production(): void
    {
        $this->app->instance('env', 'production');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Use admin:create');

        (new AdminSeeder)->run();
    }
}
