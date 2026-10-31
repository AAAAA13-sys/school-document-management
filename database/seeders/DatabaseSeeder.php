<?php

namespace Database\Seeders;

use App\Enums\Role;
use App\Enums\Source;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $password = (string) config('dms.demo_password');
        if ($password === '') {
            throw new RuntimeException('Set DMS_DEMO_PASSWORD before running the demo seeder.');
        }

        $school = 'PLP';
        $campus = 'Main';
        $accounts = [[
            'email' => 'dms.admin@plpasig.edu.ph',
            'name' => 'DMS Administrator',
            'role' => Role::SuperAdmin->value,
            // Super Admin has no primary source and can open every ERP source.
            'source' => null,
        ]];

        foreach (Source::cases() as $source) {
            $code = $source->value;
            $accounts[] = [
                'email' => strtolower($code).'@plpasig.edu.ph',
                'name' => $code.' Office',
                'role' => Role::Admin->value,
                'source' => $code,
            ];
            $accounts[] = [
                'email' => $code === Source::EMS->value ? 'employee@plpasig.edu.ph' : strtolower($code).'.staff@plpasig.edu.ph',
                'name' => $code.' Staff',
                'role' => Role::Staff->value,
                'source' => $code,
            ];
        }

        foreach ($accounts as $account) {
            User::updateOrCreate(
                ['email' => $account['email']],
                $account + [
                    'password' => $password,
                    'school_id' => $school,
                    'campus' => $campus,
                    'active' => true,
                ],
            );
        }

        $this->call(DocumentTypeSeeder::class);

        foreach (Source::cases() as $source) {
            $code = $source->value;
            $devToken = 'dms_dev_'.strtolower($code).'_token_2026';
            \Illuminate\Support\Facades\DB::table('connectors')->updateOrInsert(
                [
                    'source' => $code,
                    'school_id' => $school,
                    'campus' => $campus,
                ],
                [
                    'name' => "{$school} {$code} ERP Connector",
                    'token_hash' => hash('sha256', $devToken),
                    'active' => true,
                    'updated_at' => now(),
                    'created_at' => now(),
                ]
            );
        }

        $this->command?->info('Seeded one Super Admin, one Admin and one Staff per ERP source, plus preconfigured ERP API connectors.');
    }
}
