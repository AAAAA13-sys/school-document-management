<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class SourceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function connector(string $source = 'Enrollment', string $school = 'DEMO-SCHOOL'): void
    {
        $token = Str::random(40);
        DB::table('integration_accounts')->insert(['name' => 'Test connector', 'school_id' => $school,
            'campus' => 'Main campus', 'source' => $source, 'token_hash' => hash('sha256', $token), 'active' => true, 'created_at' => now()]);
        $this->withToken($token);
    }

    private function event(int $revision = 1, array $extra = []): array
    {
        return array_merge(['event_id' => (string) Str::uuid(), 'record_type' => 'student', 'record_id' => 'STU-1',
            'revision' => $revision, 'operation' => 'upsert', 'occurred_at' => '2026-10-07T12:00:00+08:00',
            'payload' => ['status' => 'enrolled', 'year' => 2026]], $extra);
    }

    public function test_replay_is_idempotent_and_changed_content_conflicts(): void
    {
        $this->connector();
        $event = $this->event();
        $this->postJson('/api/v1/history', $event)->assertCreated()->assertJsonPath('applied', true);
        $event['payload'] = ['year' => 2026, 'status' => 'enrolled'];
        $this->postJson('/api/v1/history', $event)->assertOk()->assertJsonPath('duplicate', true);
        $event['payload']['status'] = 'changed';
        $this->postJson('/api/v1/history', $event)->assertConflict();
        $this->assertDatabaseCount('source_history', 1);
        $this->assertDatabaseCount('audit_entries', 1);
    }

    public function test_out_of_order_updates_preserve_history_without_regressing_snapshot(): void
    {
        $this->connector();
        $this->postJson('/api/v1/history', $this->event(12))->assertCreated();
        $this->postJson('/api/v1/history', $this->event(11, ['payload' => ['status' => 'older']]))->assertCreated()->assertJsonPath('applied', false);
        $this->assertDatabaseHas('source_records', ['revision' => 12, 'deleted' => false]);
        $this->assertDatabaseCount('source_history', 2);
        $this->postJson('/api/v1/history', $this->event(12))->assertConflict();
        $this->assertDatabaseCount('source_history', 2);
    }

    public function test_deletion_is_a_tombstone_and_older_update_cannot_resurrect_it(): void
    {
        $this->connector();
        $this->postJson('/api/v1/history', $this->event(3, ['operation' => 'delete', 'payload' => []]))->assertCreated();
        $this->postJson('/api/v1/history', $this->event(2))->assertCreated()->assertJsonPath('applied', false);
        $this->assertDatabaseHas('source_records', ['revision' => 3, 'deleted' => true]);
        $this->postJson('/api/v1/history', $this->event(4, ['operation' => 'delete']))->assertUnprocessable();
    }

    public function test_source_and_school_boundaries_and_cursor_reads(): void
    {
        $this->getJson('/api/v1/history')->assertUnauthorized();
        $this->connector();
        $this->postJson('/api/v1/history', $this->event())->assertCreated();
        $page = $this->getJson('/api/v1/history?limit=1')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.source', 'Enrollment');
        $this->getJson('/api/v1/history?after='.$page['next_cursor'])->assertOk()->assertJsonCount(0, 'data');
        $this->connector('Payroll Management');
        $this->getJson('/api/v1/history')->assertOk()->assertJsonCount(0, 'data');
        $this->postJson('/api/v1/history', $this->event())->assertCreated();
        $this->connector('Enrollment', 'OTHER');
        $this->getJson('/api/v1/history')->assertOk()->assertJsonCount(0, 'data');
        $this->assertDatabaseCount('source_records', 2);
    }

    public function test_staff_can_filter_and_inspect_only_permitted_history(): void
    {
        $this->connector();
        $receipt = $this->postJson('/api/v1/history', $this->event(1))->assertCreated();
        $this->postJson('/api/v1/history', $this->event(2, ['payload' => ['status' => '<script>alert(1)</script>']]))->assertCreated();
        $this->withHeader('Authorization', '');
        $user = User::factory()->create(['role' => 'registrar', 'school_id' => 'DEMO-SCHOOL', 'campus' => 'Main campus', 'active' => true]);
        $this->actingAs($user);
        $this->getJson('/workspace/history?record_id=STU-1&source=Enrollment')->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('/workspace/history?record_id=missing')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/workspace/history/'.$receipt['history_id'])->assertOk()
            ->assertJsonPath('revision', 1)->assertJsonPath('current_revision', 2)
            ->assertJsonPath('current_payload.status', '<script>alert(1)</script>')->assertJsonMissingPath('request_hash');
        $this->get('/')->assertOk()->assertSee('Central history');
        $user->forceFill(['role' => 'payroll'])->save();
        $this->actingAs($user->fresh());
        $this->getJson('/workspace/history/'.$receipt['history_id'])->assertNotFound();
        $this->getJson('/workspace/history?source=Enrollment')->assertOk()->assertJsonCount(0, 'data');
        $user->forceFill(['role' => 'registrar', 'school_id' => 'OTHER'])->save();
        $this->actingAs($user->fresh());
        $this->getJson('/workspace/history/'.$receipt['history_id'])->assertNotFound();
    }
}
