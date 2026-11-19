<?php

namespace Database\Seeders;

use App\Models\AccessControlEntry;
use App\Models\User;
// use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // User::factory(10)->create();

        $admin = User::create([
            'username' => 'admin',
            'password' => 'password',
            'note' => 'Admin User',
            'is_admin' => true,
            'can_share' => true,
        ]);

        // Nothing is accessible unless a rule grants it.
        AccessControlEntry::create(['path' => ''])->users()->attach($admin);
    }
}
