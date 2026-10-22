<?php

namespace Database\Seeders;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        $email = 'bolajimiracle6@gmail.com';

        $user = User::firstOrNew(['email' => $email]);
        $user->name     = 'Bolaji';
        $user->email    = $email;
        $user->password = Hash::make('Handerson1@');
        $user->forceFill([
            'role'              => UserRole::Admin,
            'email_verified_at' => now(),
        ])->save();

        $this->command->info("Admin user {$email} ready.");
    }
}
