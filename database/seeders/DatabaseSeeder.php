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
     *
     * Create the first administrator with
     * `php artisan users:bootstrap-administrator`, which asks for a name, an
     * address and a password, runs only while the `users` table is completely
     * empty, and records what it did. Every account after that is created by an
     * administrator in the panel, under Identity → User accounts.
     */
    public function run(): void
    {
        //
    }
}
