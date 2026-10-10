<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StudentFilesTest extends TestCase
{
    use RefreshDatabase;

    private function upload(string $filename, string $title, string $key)
    {
        return $this->withHeader('Idempotency-Key', $key)->post('/workspace/documents', [
            'title'=>$title, 'subject'=>'Student', 'reference'=>'PERSONAL-1', 'source'=>'Enrollment',
            'category'=>'Academic record', 'classification'=>'Confidential',
            'file'=>UploadedFile::fake()->createWithContent($filename, 'Personal academic notes.'),
        ], ['Accept'=>'application/json']);
    }

    public function test_unique_names_are_case_insensitive_private_and_reserved_in_trash(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']);
        $this->actingAs($owner);
        $first = $this->upload('Notes.txt', 'My notes', 'student-name-001')->assertCreated();
        $this->get('/workspace/files/'.$first['version_id'])->assertOk();
        $this->get('/workspace/preview/'.$first['version_id'])->assertOk();
        $this->upload('NOTES.TXT', 'Different label', 'student-name-002')->assertConflict();
        $this->upload('other.txt', 'MY NOTES', 'student-name-003')->assertConflict();
        $this->postJson('/workspace/drive/'.$first['document_id'].'/action', ['action'=>'trash','revision'=>1])->assertOk();
        $this->upload('Notes.txt', 'New notes', 'student-name-004')->assertConflict();
        $this->actingAs(User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']));
        $this->upload('Notes.txt', 'My notes', 'student-name-005')->assertCreated();
        $this->assertDatabaseCount('documents', 2);
    }

    public function test_rename_and_database_constraint_prevent_collisions(): void
    {
        Storage::fake('local');
        $this->actingAs(User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']));
        $first = $this->upload('a.txt', 'First', 'student-name-101')->assertCreated();
        $second = $this->upload('b.txt', 'Second', 'student-name-102')->assertCreated();
        $this->postJson('/workspace/drive/'.$second['document_id'].'/action', ['action'=>'rename','title'=>'FIRST','revision'=>1])->assertConflict();
        $this->assertDatabaseHas('documents', ['id'=>$second['document_id'],'title'=>'Second','revision'=>1]);
        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        DB::table('documents')->where('id', $second['document_id'])->update(['filename_key'=>DB::table('documents')->where('id', $first['document_id'])->value('filename_key')]);
    }

    public function test_student_shell_uses_personal_navigation_without_scan_instructions(): void
    {
        $this->actingAs(User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']));
        $this->get('/')->assertOk()->assertSee('student-workspace.css')->assertSee('data-student="true"', false)
            ->assertSee('Shared with me')->assertDontSee('Security checks must pass')->assertDontSee('School repository');
    }
}
