<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_personal_roles_own_their_workspace_and_cannot_access_other_users_or_admin_data(): void
    {
        Storage::fake('local');
        foreach (['student', 'teacher', 'employee'] as $role) {
            $owner = User::factory()->create(['role' => $role, 'active' => true, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus']);
            $other = User::factory()->create(['role' => $role, 'active' => true, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus']);
            $source = $role === 'student' ? 'Enrollment' : 'Employee Management';
            $this->actingAs($owner)->get('/')->assertOk()->assertDontSee('Central history')->assertDontSee('Accounts &amp; roles', false);
            $this->get('/')->assertDontSee('id="repository-panel"', false)->assertDontSee('data-view="documents"', false)->assertSee('Ready for your next school day.');
            $file = $this->withHeader('Idempotency-Key', 'role-upload-'.$role)->post('/workspace/documents', [
                'title' => 'My evidence', 'subject' => 'Person', 'reference' => 'REF-1', 'source' => $source,
                'category' => 'Identity evidence', 'classification' => 'Confidential',
                'owner_user_id' => $other->id,
                'file' => UploadedFile::fake()->createWithContent('evidence.txt', 'Private synthetic evidence.'),
            ], ['Accept' => 'application/json'])->assertCreated()->json();
            $this->assertDatabaseHas('documents', ['id' => $file['document_id'], 'owner_user_id' => $owner->id]);
            DB::table('document_versions')->where('id', $file['version_id'])->update(['scan' => 'Clean']);
            $this->get('/workspace/files/'.$file['version_id'])->assertOk();
            $folder = $this->postJson('/workspace/folders', ['name' => 'My folder', 'source' => $source])->assertCreated()['id'];
            $this->getJson('/workspace/folders/'.$folder)->assertOk()->assertJsonPath('activity.0.action', 'Folder created');
            $this->getJson('/workspace/documents/'.$file['document_id'])->assertOk();
            $this->getJson('/workspace/stats')->assertJsonPath('total', 1);
            foreach (['history', 'events', 'audit'] as $endpoint) {
                $this->getJson('/workspace/'.$endpoint)->assertForbidden();
            }
            $this->get('/accounts')->assertForbidden();
            $this->postJson('/workspace/folders', ['name' => 'Payroll', 'source' => 'Payroll Management'])->assertForbidden();
            $this->actingAs($other)->getJson('/workspace/documents/'.$file['document_id'])->assertNotFound();
            $this->getJson('/workspace/files/'.$file['version_id'])->assertNotFound();
            $this->getJson('/workspace/preview/'.$file['version_id'])->assertNotFound();
            $this->getJson('/workspace/drive')->assertJsonCount(0, 'files.data')->assertJsonCount(0, 'folders');
            $this->getJson('/workspace/drive?folder='.$folder)->assertNotFound();
            $this->getJson('/workspace/folders/'.$folder)->assertNotFound();
            $this->postJson('/workspace/drive/'.$file['document_id'].'/action', ['action' => 'trash', 'revision' => 1])->assertNotFound();
        }
    }

    public function test_admin_account_management_is_scoped_validated_and_audited(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'active' => true, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus']);
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('School repository')->assertSee('id="repository-panel"', false);
        $this->get('/accounts')->assertOk()->assertSee('Existing accounts')->assertSee('Administrator')->assertSee('Create account');
        $this->actingAs($admin)->post('/accounts', ['name' => 'Student One', 'email' => 'student@example.test',
            'role' => 'student', 'password' => 'Student-pass-2026!', 'password_confirmation' => 'Student-pass-2026!',
        ])->assertRedirect();
        $student = User::where('email', 'student@example.test')->firstOrFail();
        $this->assertSame($admin->school_id, $student->school_id);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('Student-pass-2026!', $student->password));
        $this->patch('/accounts/'.$student->id, ['role' => 'employee', 'active' => 0])->assertRedirect();
        $this->assertDatabaseHas('users', ['id' => $student->id, 'role' => 'employee', 'active' => 0]);
        $this->patchJson('/accounts/'.$admin->id, ['role' => 'student', 'active' => 0])->assertUnprocessable();
        $foreign = User::factory()->create(['school_id' => 'OTHER']);
        $this->patchJson('/accounts/'.$foreign->id, ['role' => 'student', 'active' => 1])->assertNotFound();
        $this->patchJson('/accounts/'.$student->id, ['role' => 'superuser', 'active' => 1])->assertUnprocessable();
        $this->assertDatabaseHas('audit_entries', ['action' => 'Account access changed']);
        $this->actingAs($student->fresh())->get('/')->assertForbidden();
        $this->getJson('/workspace/drive')->assertForbidden();
    }

    public function test_personal_roles_cannot_create_accounts_or_promote_themselves(): void
    {
        $student = User::factory()->create(['role' => 'student', 'active' => true, 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus']);
        $this->actingAs($student)->postJson('/accounts', [])->assertForbidden();
        $this->patchJson('/accounts/'.$student->id, ['role' => 'admin', 'active' => 1])->assertForbidden();
        $this->assertSame('student', $student->fresh()->role);
    }
}
