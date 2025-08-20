<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class RolesTableSeeder extends Seeder
{
    public function run(): void
    {
        $admin = Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        $user  = Role::firstOrCreate(['slug' => 'user'],  ['name' => 'User']);

       
        if (!User::where('email', 'admin@example.com')->exists()) {
            $u = User::create([
                'name' => 'Admin',
                'email' => 'admin@example.com',
                'password' => Hash::make('password'), 
                'role_id' => $admin->id,
            ]);
        }
    }
}
