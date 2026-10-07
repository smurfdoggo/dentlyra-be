<?php

namespace Database\Seeders;

use App\Models\Admin;
use Illuminate\Database\Seeder;
use LogicException;

class AdminSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment(['local', 'testing'])) {
            throw new LogicException('Use admin:create to provision administrators outside local development.');
        }

        Admin::query()->firstOrCreate(
            ['email' => 'admin@example.com'],
            ['name' => 'Development Admin', 'password' => 'ChangeMe123!'],
        );
    }
}
