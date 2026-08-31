<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     *
     * Administrative accounts are deliberately not seeded: a shared, known
     * password must never exist in the repository or in any environment.
     * Create the first account with `php artisan make:filament-user`.
     */
    public function run(): void
    {
        //
    }
}
