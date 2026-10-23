<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ApplicantPacketService;
use App\Services\SensitiveChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SensitiveChangesAndPacketsTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'staff', string $source = 'OAS'): User
    {
        return User::factory()->create(['role' => $role, 'source' => $role === 'super_admin' ? null : $source, 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    private function document(User $owner): object
    {
        $id = (string) Str::uuid();
        DB::table('documents')->insert(['id' => $id, 'owner_id' => $owner->id, 'source' => 'OAS', 'school_id' => 'PLP', 'campus' => 'Main', 'title' => 'Admission evidence', 'classification' => 'Internal', 'revision' => 1, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('documents')->where('id', $id)->first();
    }

    private function version(object $doc): string
    {
        $id = (string) Str::uuid();
        DB::table('document_versions')->insert(['id' => $id, 'document_id' => $doc->id, 'number' => 1, 'filename' => 'proof.txt', 'mime' => 'text/plain', 'size' => 4, 'checksum' => hash('sha256', 'test'), 'storage_key' => 'documents/'.$id, 'scan_status' => 'Clean', 'created_at' => now()]);
        return $id;
    }

    private function connector(string $source): object
    {
        $id = DB::table('connectors')->insertGetId(['name' => $source, 'source' => $source, 'school_id' => 'PLP', 'campus' => 'Main', 'token_hash' => hash('sha256', $source), 'active' => true, 'created_at' => now(), 'updated_at' => now()]);
        return DB::table('connectors')->where('id', $id)->first();
    }

    public function test_staff_change_stays_pending_then_scoped_admin_applies_once(): void
    {
        $staff = $this->user(); $admin = $this->user('admin'); $doc = $this->document($staff);
        $service = app(SensitiveChangeService::class);
        $request = $service->submit($staff, $doc, 'classification', ['revision' => 1, 'classification' => 'Confidential']);
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'classification' => 'Internal', 'revision' => 1]);
        $service->decide($admin, $request['approval_id'], true, 'Office access confirmed.');
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'classification' => 'Confidential', 'revision' => 2]);
        $this->expectException(HttpException::class);
        $service->decide($admin, $request['approval_id'], true, 'Repeated decision.');
    }

    public function test_changed_revision_cannot_be_approved(): void
    {
        $staff = $this->user(); $doc = $this->document($staff); $admin = $this->user('admin');
        $service = app(SensitiveChangeService::class);
        $pending = $service->submit($staff, $doc, 'trash', ['revision' => 1]);
        DB::table('documents')->where('id', $doc->id)->update(['revision' => 2]);
        try { $service->decide($admin, $pending['approval_id'], true, 'Approved.'); $this->fail('Expected stale revision rejection.'); }
        catch (HttpException $e) { $this->assertSame(409, $e->getStatusCode()); }
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'trashed_at' => null]);
        $this->assertDatabaseHas('sensitive_changes', ['id' => $pending['approval_id'], 'status' => 'pending']);
    }

    public function test_memo_circulation_pins_clean_approved_version(): void
    {
        $staff = $this->user(); $doc = $this->document($staff); $version = $this->version($doc); $reader = $this->user();
        $service = app(SensitiveChangeService::class);
        $pending = $service->submit($staff, $doc, 'memo.publish', ['revision' => 1, 'version_id' => $version, 'type' => 'Memo', 'reference' => 'M-1', 'issuer' => 'Registrar', 'audience' => [$reader->id]]);
        $this->assertDatabaseCount('memo_publications', 0);
        $service->decide($this->user('admin'), $pending['approval_id'], true, 'Ready for circulation.');
        $this->assertDatabaseHas('shares', ['recipient_id' => $reader->id, 'version_id' => $version, 'permission' => 'Viewer']);
        $this->assertSame($version, \App\Services\MemoService::circulatedVersion($reader, $doc));
    }

    public function test_packet_exact_version_replay_and_revocation(): void
    {
        $doc = $this->document($this->user()); $version = $this->version($doc); $oas = $this->connector('OAS'); $es = $this->connector('ES');
        $service = app(ApplicantPacketService::class);
        $event = ['event_id' => 'release-1', 'applicant_id' => 'A-1', 'revision' => 1, 'released' => true, 'admitted' => true, 'verified' => true, 'version_ids' => [$version]];
        [$first, $status] = $service->receive($oas, $event); $this->assertSame(201, $status);
        [$again, $status] = $service->receive($oas, $event); $this->assertSame(200, $status); $this->assertSame($first, $again);
        $packet = $service->read($es, $first['packet_id']); $this->assertSame([$version], array_column($packet['versions'], 'version_id'));
        $service->receive($oas, ['event_id' => 'revoke-2', 'applicant_id' => 'A-1', 'revision' => 2, 'released' => false]);
        $this->expectException(HttpException::class);
        $service->read($es, $first['packet_id']);
    }

    public function test_packet_rejects_medical_document(): void
    {
        $doc = $this->document($this->user()); $version = $this->version($doc);
        DB::table('documents')->where('id', $doc->id)->update(['category' => 'Medical certificate']);
        $this->expectException(HttpException::class);
        app(ApplicantPacketService::class)->receive($this->connector('OAS'), ['event_id' => 'medical', 'applicant_id' => 'A-2', 'revision' => 1, 'released' => true, 'admitted' => true, 'verified' => true, 'version_ids' => [$version]]);
    }

    public function test_super_admin_immediate_result_matches_persisted_revision(): void
    {
        $sa = $this->user('super_admin'); $doc = $this->document($sa);
        $result = app(SensitiveChangeService::class)->submit($sa, $doc, 'trash', ['revision' => 1]);
        $this->assertSame(2, $result['revision']);
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'revision' => 2]);
    }

    public function test_malformed_share_expiry_returns_validation_status(): void
    {
        $staff = $this->user(); $doc = $this->document($staff); $recipient = $this->user();
        try {
            app(SensitiveChangeService::class)->submit($staff, $doc, 'share.grant', ['revision' => 1, 'recipient_id' => $recipient->id, 'permission' => 'Viewer', 'expires_at' => 'not a date']);
            $this->fail('Malformed expiry accepted.');
        } catch (HttpException $e) { $this->assertSame(422, $e->getStatusCode()); }
    }

    public function test_republication_revokes_only_removed_memo_generated_audience(): void
    {
        $sa = $this->user('super_admin'); $doc = $this->document($sa); $version = $this->version($doc);
        $old = $this->user(); $manual = $this->user(); $new = $this->user(); $service = app(SensitiveChangeService::class);
        $service->submit($sa, $doc, 'share.grant', ['revision' => 1, 'recipient_id' => $manual->id, 'permission' => 'Viewer']);
        $payload = ['version_id' => $version, 'type' => 'Memo', 'reference' => 'M-1', 'issuer' => 'Registrar'];
        $service->submit($sa, $doc, 'memo.publish', $payload + ['revision' => 2, 'audience' => [$old->id, $manual->id]]);
        $service->submit($sa, $doc, 'memo.publish', $payload + ['revision' => 3, 'audience' => [$new->id]]);
        $this->assertNotNull(DB::table('shares')->where('recipient_id', $old->id)->value('revoked_at'));
        $this->assertDatabaseHas('shares', ['recipient_id' => $manual->id, 'revoked_at' => null, 'memo_generated' => false]);
        $this->assertDatabaseHas('shares', ['recipient_id' => $new->id, 'revoked_at' => null, 'memo_generated' => true]);
    }

    public function test_restricted_memo_cannot_bypass_super_admin_share_authority(): void
    {
        $staff = $this->user(); $doc = $this->document($staff); $version = $this->version($doc); $doc->classification = 'Restricted';
        DB::table('documents')->where('id', $doc->id)->update(['classification' => 'Restricted']);
        try {
            app(SensitiveChangeService::class)->submit($staff, $doc, 'memo.publish', ['revision' => 1, 'version_id' => $version, 'type' => 'Memo', 'reference' => 'M-1', 'issuer' => 'Owner', 'audience' => [$this->user()->id]]);
            $this->fail('Restricted publication accepted.');
        } catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
    }

    public function test_self_approval_is_refused_and_suspended_requester_cannot_apply(): void
    {
        $admin = $this->user('admin'); $doc = $this->document($admin); $service = app(SensitiveChangeService::class);
        $pending = $service->submit($admin, $doc, 'trash', ['revision' => 1]);
        try { $service->decide($admin, $pending['approval_id'], true, 'Self decision.'); $this->fail('Self approval accepted.'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $admin->update(['active' => false]);
        try { $service->decide($this->user('super_admin'), $pending['approval_id'], true, 'Approved.'); $this->fail('Inactive requester accepted.'); }
        catch (HttpException $e) { $this->assertSame(403, $e->getStatusCode()); }
        $this->assertDatabaseHas('documents', ['id' => $doc->id, 'revision' => 1, 'trashed_at' => null]);
    }
}
