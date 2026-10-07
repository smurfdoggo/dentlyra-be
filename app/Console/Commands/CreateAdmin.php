<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

#[Signature('admin:create {--name= : Administrator name} {--email= : Administrator email}')]
#[Description('Create an administrator account with a securely prompted password')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        if (! $this->input->isInteractive()) {
            $this->error('Run this command interactively to securely enter the administrator password.');

            return self::FAILURE;
        }

        $attributes = [
            'name' => $this->option('name') ?? $this->ask('Name'),
            'email' => $this->option('email') ?? $this->ask('Email'),
            'password' => $this->secret('Password'),
            'password_confirmation' => $this->secret('Confirm password'),
        ];

        $validator = Validator::make($attributes, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:admins,email'],
            'password' => ['required', 'string', 'max:72', 'confirmed', Password::min(12)->mixedCase()->numbers()->symbols()],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            return self::FAILURE;
        }

        Admin::query()->create(collect($validator->validated())->only(['name', 'email', 'password'])->all());

        $this->info('Administrator created successfully.');

        return self::SUCCESS;
    }
}
