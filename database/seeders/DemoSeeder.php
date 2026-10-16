<?php

namespace Database\Seeders;

use App\Models\User;
use App\Services\DocumentService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class DemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Demo seeding is disabled outside local/testing environments.');
        }
        $password = config('dms.demo_password');
        if (! $password) {
            throw new \RuntimeException('Set DMS_DEMO_PASSWORD before seeding.');
        }
        foreach (['employee' => 'Demo Employee', 'admin' => 'Records Administrator', 'registrar' => 'Admissions Registrar', 'hr' => 'HR Officer', 'payroll' => 'Payroll Officer'] as $role => $name) {
            $u = User::firstOrNew(['email' => $role.'@demo.school']);
            $u->forceFill(['name' => $name, 'password' => $password, 'role' => $role, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'active' => true])->save();
            $users[$role] = $u->id;
        }
        // Personal workspaces are scoped by owner_user_id, so seeded records need a real
        // owner or a staff account signs in to an empty workspace. Each source belongs
        // to the office role that owns it.
        $ownerFor = ['Employee Management' => 'employee', 'Online Admission' => 'registrar', 'Enrollment' => 'registrar', 'Payroll Management' => 'payroll'];
        if (DB::table('documents')->exists()) {
            return;
        }
        $folders = [];
        foreach ([['Staff Records', null], ['Contracts 2026', 'Staff Records']] as [$name, $parent]) {
            $id = (string) Str::uuid();
            DB::table('folders')->insert(['id' => $id, 'parent_id' => $folders[$parent] ?? null, 'name' => $name, 'source' => 'Employee Management', 'owner_user_id' => $users['employee'], 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $folders[$name] = $id;
        }
        $examples = [
            ['Birth certificate', 'Andrea Santos', 'APP-2026-0142', 'Online Admission', 'Identity evidence', 'Restricted', 'Available'],
            ['Senior high school transcript', 'Miguel Reyes', 'APP-2026-0138', 'Online Admission', 'Academic record', 'Confidential', 'Available'],
            ['Enrollment confirmation', 'Sofia Garcia', 'ENR-2026-0281', 'Enrollment', 'Enrollment form', 'Confidential', 'Available'],
            ['Employment agreement', 'Carlo Mendoza', 'EMP-0042', 'Employee Management', 'Employment contract', 'Restricted', 'Available'],
            ['September payslip', 'Isabel Cruz', 'PAY-2026-09-0058', 'Payroll Management', 'Payslip', 'Restricted', 'Available'],
            ['Parent consent form', 'Lucas Ramos', 'ENR-2026-0279', 'Enrollment', 'Consent form', 'Confidential', 'Available'],
            ['Teaching qualification', 'Patricia Flores', 'EMP-0031', 'Employee Management', 'Qualification', 'Confidential', 'Available'],
            ['Records handling policy', 'School administration', 'POL-0001', 'Employee Management', 'School policy', 'Internal', 'Available'],
        ];
        foreach ($examples as $i => $x) {
            [$title,$subject,$ref,$source,$category,$classification,$status] = $x;
            $id = (string) Str::uuid();
            $v = (string) Str::uuid();
            $key = 'documents/'.$v;
            $content = "SYNTHETIC DEMONSTRATION RECORD\n\n$title\n$subject\n$ref\n\nThis sample contains no real school records.\n";
            Storage::disk('local')->put($key, $content);
            $owner = $users[$ownerFor[$source]] ?? null;
            $folder = $title === 'Employment agreement' ? $folders['Contracts 2026'] : null;
            DB::transaction(function () use ($id, $v, $key, $content, $title, $subject, $ref, $source, $category, $classification, $status, $i, $owner, $folder) {
                DB::table('documents')->insert(['id' => $id, 'title' => $title, 'subject' => $subject, 'reference' => $ref, 'source' => $source, 'category' => $category, 'classification' => $classification, 'owner_user_id' => $owner, 'folder_id' => $folder, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'revision' => 1, 'created_at' => now()->subDays($i), 'updated_at' => now()->subHours($i)]);
                DB::table('document_versions')->insert(['id' => $v, 'document_id' => $id, 'number' => 1, 'filename' => 'demonstration.txt', 'media_type' => 'text/plain', 'size' => strlen($content), 'checksum' => hash('sha256', $content), 'storage_key' => $key, 'scan' => 'Pending', 'status' => $status, 'actor' => 'Synthetic demo import', 'reason' => null, 'reviewer' => null, 'reviewed_at' => null, 'created_at' => now()->subDays($i), 'expires_at' => $category === 'Qualification' ? now()->addDays(21)->format('Y-m-d') : null]);
                $service = app(DocumentService::class);
                $a = (object) ['name' => 'Synthetic demo import', 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus'];
                $service->log($a, 'Demo record created', $id, 'Synthetic example only');
                $service->event(DB::table('documents')->where('id', $id)->first(), 'document.received', $v);
            });
        }
    }
}
