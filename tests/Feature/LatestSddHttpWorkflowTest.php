<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class LatestSddHttpWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $role = 'staff'): User
    {
        return User::factory()->create(['role' => $role, 'source' => $role === 'super_admin' ? null : 'OAS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    private function document(User $owner): object
    {
        $id = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $id, 'owner_id' => $owner->id, 'source' => 'OAS', 'school_id' => 'PLP', 'campus' => 'Main', 'title' => 'Official memo', 'classification' => 'Internal', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('documents')->where('id', $id)->first();
    }

    private function version(object $doc, int $number): string
    {
        $id = (string) Str::uuid();
        $bytes = 'memo-version-'.$number;
        Storage::disk('local')->put('documents/'.$id, $bytes);
        DB::table('document_versions')->insert(['id' => $id, 'document_id' => $doc->id, 'number' => $number, 'filename' => 'memo-v'.$number.'.txt', 'mime' => 'text/plain', 'size' => strlen($bytes), 'checksum' => hash('sha256', $bytes), 'storage_key' => 'documents/'.$id, 'scan_status' => 'Clean', 'created_at' => now()]);

        return $id;
    }

    public function test_http_sensitive_changes_only_create_pending_requests(): void
    {
        $owner = $this->account();
        $recipient = $this->account();
        $doc = $this->document($owner);
        $this->actingAs($owner)->postJson('/documents/'.$doc->id.'/shares', ['revision' => 1, 'recipient_id' => $recipient->id, 'permission' => 'Viewer'])->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->assertDatabaseCount('shares', 0);
        $this->postJson('/documents/'.$doc->id.'/trash', ['revision' => 1])->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->patchJson('/documents/'.$doc->id, ['revision' => 1, 'title' => 'Official memo', 'classification' => 'Confidential'])->assertStatus(202)->assertJsonPath('status', 'pending');
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'classification' => 'Internal', 'revision' => 1, 'trashed_at' => null]);
        $this->get('/approvals')->assertOk()->assertSee('Proposed changes');
    }

    public function test_memo_http_publication_pins_detail_status_and_downloads(): void
    {
        Storage::fake('local');
        $owner = $this->account();
        $reader = $this->account();
        $admin = $this->account('admin');
        $doc = $this->document($owner);
        $v1 = $this->version($doc, 1);
        $this->actingAs($owner)->get('/documents/'.$doc->id)->assertOk()->assertSee('Publish an official memo');
        $response = $this->postJson('/documents/'.$doc->id.'/publish', ['revision' => 1, 'version_id' => $v1, 'type' => 'Office memo', 'reference' => 'R-1', 'issuer' => 'Registrar', 'audience' => [$reader->id]])->assertStatus(202);
        $this->actingAs($admin)->postJson('/approvals/'.$response->json('approval_id'), ['decision' => 'approve', 'reason' => 'Approved official version.'])->assertOk();
        $v2 = $this->version($doc, 2);
        DB::table('documents')->where('id', $doc->id)->update(['revision' => 3]);
        $this->actingAs($reader)->get('/documents/'.$doc->id)->assertOk()->assertSee('memo-v1.txt')->assertDontSee('memo-v2.txt');
        $this->getJson('/documents/'.$doc->id.'/scan-status')->assertOk()->assertJsonCount(1, 'versions')->assertJsonPath('versions.0.id', $v1);
        $this->get('/files/'.$v1)->assertOk();
        $this->get('/files/'.$v2)->assertForbidden();
    }

    public function test_packet_http_validation_and_exact_version_reads(): void
    {
        Storage::fake('local');
        $doc = $this->document($this->account());
        $version = $this->version($doc, 1);
        foreach (['OAS', 'ES'] as $source) {
            DB::table('connectors')->insert(['name' => $source, 'source' => $source, 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', 'token-'.$source), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->withToken('token-OAS')->postJson('/api/v1/applicant-packets', [])->assertUnprocessable()->assertJsonValidationErrors(['event_id', 'applicant_id', 'revision', 'released']);
        $body = ['event_id' => 'admitted', 'applicant_id' => 'A-100', 'revision' => 1, 'released' => true, 'admitted' => true, 'verified' => true, 'version_ids' => [$version]];
        $release = $this->withToken('token-OAS')->postJson('/api/v1/applicant-packets', $body)->assertCreated();
        $packet = $release->json('packet_id');
        $this->withToken('token-ES')->getJson('/api/v1/applicant-packets/'.$packet)->assertOk()->assertJsonPath('versions.0.version_id', $version);
        $this->get('/api/v1/applicant-packets/'.$packet.'/files/'.$version)->assertOk();
        $this->getJson('/api/v1/documents/'.$doc->id)->assertNotFound();
    }
}
