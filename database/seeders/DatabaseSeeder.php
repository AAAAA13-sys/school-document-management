<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $accounts = [
            [
                'email' => 'dms.admin@plpasig.edu.ph',
                'name' => 'DMS Administrator',
                'password' => 'DMS2026!',
                'role' => 'super_admin',
                'source' => 'DMS',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
            [
                'email' => 'ems@plpasig.edu.ph',
                'name' => 'EMS Office',
                'password' => 'ERP2026!',
                'role' => 'admin',
                'source' => 'EMS',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
            [
                'email' => 'pms@plpasig.edu.ph',
                'name' => 'PMS Office',
                'password' => 'ERP2026!',
                'role' => 'admin',
                'source' => 'PMS',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
            [
                'email' => 'es@plpasig.edu.ph',
                'name' => 'ES Office',
                'password' => 'ERP2026!',
                'role' => 'admin',
                'source' => 'ES',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
            [
                'email' => 'oas@plpasig.edu.ph',
                'name' => 'OAS Office',
                'password' => 'ERP2026!',
                'role' => 'admin',
                'source' => 'OAS',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
            [
                'email' => 'employee@plpasig.edu.ph',
                'name' => 'General Employee',
                'password' => 'ERP2026!',
                'role' => 'staff',
                'source' => 'EMS',
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => true,
            ],
        ];

        foreach ($accounts as $data) {
            User::updateOrCreate(
                ['email' => $data['email']],
                $data
            );
        }

        $this->command?->info('PLP ERP and DMS Admin accounts seeded successfully.');
    }
}
