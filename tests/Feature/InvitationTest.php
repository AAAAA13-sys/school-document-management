<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HierarchicalInvitationService as InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class InvitationTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'super_admin', string $source = 'EMS'): User
    {
        return User::factory()->create(['role' => $role, 'source' => $source, 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    public function test_staff_proposal_requires_distinct_super_admin_and_is_applied_once(): void
    {
        $service = app(InvitationService::class);
        $admin = $this->user('admin');
        $id = $service->request($admin, ['name' => 'New Staff', 'email' => 'new@example.test', 'role' => 'staff', 'source' => 'EMS']);
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'pending']);
        $service->review($this->user(), $id, true, 'Approved staffing request');
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'approved']);
        $this->expectException(HttpException::class);
        $service->review($this->user(), $id, false, 'Second decision');
    }

    public function test_acceptance_uses_recipient_password_and_cannot_be_replayed(): void
    {
        $service = app(InvitationService::class);
        $id = $service->request($this->user(), ['name' => 'New Admin', 'email' => 'admin@example.test', 'role' => 'admin', 'source' => 'EMS']);
        DB::table('account_invitations')->where('id', $id)->update(['status' => 'sent', 'token_hash' => hash('sha256', 'secret-token'), 'expires_at' => now()->addHour()]);
        $user = $service->accept('secret-token', 'LongRecipientPassword!');
        $this->assertTrue(Hash::check('LongRecipientPassword!', $user->password));
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'accepted', 'token_hash' => null]);
        $this->expectException(HttpException::class);
        $service->accept('secret-token', 'AnotherPassword!');
    }

    public function test_expired_invitation_does_not_create_an_account(): void
    {
        $service = app(InvitationService::class);
        $id = $service->request($this->user(), ['name' => 'Expired', 'email' => 'expired@example.test', 'role' => 'staff', 'source' => 'EMS']);
        DB::table('account_invitations')->where('id', $id)->update(['status' => 'sent', 'token_hash' => hash('sha256', 'expired'), 'expires_at' => now()->subMinute()]);
        try {
            $service->accept('expired', 'LongRecipientPassword!');
            $this->fail('Expired token accepted');
        } catch (HttpException $e) {
            $this->assertSame(410, $e->getStatusCode());
        }
        $this->assertDatabaseMissing('users', ['email' => 'expired@example.test']);
    }

    public function test_suspended_inviter_invalidates_activation(): void
    {
        $service = app(InvitationService::class);
        $actor = $this->user();
        $id = $service->request($actor, ['name' => 'Waiting', 'email' => 'waiting@example.test', 'role' => 'staff', 'source' => 'EMS']);
        DB::table('account_invitations')->where('id', $id)->update(['status' => 'sent', 'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addHour()]);
        $actor->update(['active' => false]);
        $this->expectException(HttpException::class);
        $service->accept('token', 'LongRecipientPassword!');
    }

    public function test_revocation_clears_token_and_blocks_acceptance(): void
    {
        $service = app(InvitationService::class);
        $actor = $this->user();
        $id = $service->request($actor, ['name' => 'Waiting', 'email' => 'waiting@example.test', 'role' => 'staff', 'source' => 'EMS']);
        DB::table('account_invitations')->where('id', $id)->update(['status' => 'sent', 'token_hash' => hash('sha256', 'token'), 'expires_at' => now()->addHour()]);
        $service->revoke($actor, $id);
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'revoked', 'token_hash' => null]);
        $this->expectException(HttpException::class);
        $service->accept('token', 'LongRecipientPassword!');
    }

    public function test_invitation_screen_and_password_recovery_lifecycle(): void
    {
        $actor = $this->user();
        $this->actingAs($actor)->get('/admin/invitations')->assertOk()->assertSee('Account invitations');
        $this->post('/logout');
        $token = Password::createToken($actor);
        $before = $actor->fresh()->auth_version;
        $this->get('/reset-password/'.$token.'?email='.rawurlencode($actor->email))->assertOk()->assertSee('Reset your password');
        $this->post('/reset-password', ['email' => $actor->email, 'token' => $token, 'password' => 'NewSecurePassword123!', 'password_confirmation' => 'NewSecurePassword123!'])->assertRedirect('/login');
        $this->assertTrue(Hash::check('NewSecurePassword123!', $actor->fresh()->password));
        $this->assertSame($before + 1, $actor->fresh()->auth_version);
        $this->post('/reset-password', ['email' => $actor->email, 'token' => $token, 'password' => 'AnotherPassword123!', 'password_confirmation' => 'AnotherPassword123!'])->assertSessionHasErrors('email');
    }

    public function test_delivery_transport_failure_preserves_approved_state(): void
    {
        config(['mail.default' => 'smtp']);
        Mail::shouldReceive('raw')->once()->andThrow(new \RuntimeException('Transport unavailable'));
        $actor = $this->user();
        $service = app(InvitationService::class);
        $id = $service->request($actor, ['name' => 'Waiting', 'email' => 'waiting@example.test', 'role' => 'staff', 'source' => 'EMS']);
        try {
            $service->deliver($actor, $id);
            $this->fail('Transport did not fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('Transport unavailable', $e->getMessage());
        }
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'approved', 'token_hash' => null, 'sent_at' => null]);
    }

    public function test_log_mailer_cannot_leak_activation_tokens(): void
    {
        config(['mail.default' => 'log']);
        $service = app(InvitationService::class);
        $actor = $this->user();
        $id = $service->request($actor, ['name' => 'Waiting', 'email' => 'waiting@example.test', 'role' => 'staff', 'source' => 'EMS']);
        try {
            $service->deliver($actor, $id);
            $this->fail('Log mail transport accepted');
        } catch (HttpException $e) {
            $this->assertSame(503, $e->getStatusCode());
        }
        $this->assertDatabaseHas('account_invitations', ['id' => $id, 'status' => 'approved', 'token_hash' => null]);
    }

    public function test_super_admin_store_invitation_with_log_mailer_is_graceful_and_records_approved(): void
    {
        config(['mail.default' => 'log']);
        $superAdmin = $this->user('super_admin');

        // Test JSON request
        $jsonResp = $this->actingAs($superAdmin)
            ->postJson('/admin/invitations', [
                'name' => 'Graceful User',
                'email' => 'graceful@plp.edu.ph',
                'role' => 'staff',
                'source' => 'EMS',
            ]);

        $jsonResp->assertStatus(201)
            ->assertJson([
                'status' => 'approved',
            ])
            ->assertJsonStructure(['id', 'status', 'message']);

        $this->assertDatabaseHas('account_invitations', [
            'email' => 'graceful@plp.edu.ph',
            'status' => 'approved',
            'token_hash' => null,
        ]);

        // Test Web request
        $webResp = $this->actingAs($superAdmin)
            ->post('/admin/invitations', [
                'name' => 'Graceful Web User',
                'email' => 'graceful-web@plp.edu.ph',
                'role' => 'staff',
                'source' => 'EMS',
            ]);

        $webResp->assertRedirect();
        $webResp->assertSessionHas('status');
        $this->assertDatabaseHas('account_invitations', [
            'email' => 'graceful-web@plp.edu.ph',
            'status' => 'approved',
        ]);
    }

    public function test_super_admin_account_creation_with_log_mailer_is_graceful(): void
    {
        config(['mail.default' => 'log']);
        $superAdmin = $this->user('super_admin');

        $response = $this->actingAs($superAdmin)
            ->postJson('/admin/accounts', [
                'name' => 'Account User',
                'email' => 'account-user@plp.edu.ph',
                'role' => 'staff',
                'source' => 'EMS',
                'active' => true,
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'approved',
            ])
            ->assertJsonStructure(['id', 'status', 'message']);

        $this->assertDatabaseHas('account_invitations', [
            'email' => 'account-user@plp.edu.ph',
            'status' => 'approved',
        ]);
    }

    public function test_admin_proposes_staff_invitation_creates_pending_status(): void
    {
        $admin = $this->user('admin', 'EMS');
        $superAdmin = $this->user('super_admin');

        // Propose invitation as admin
        $response = $this->actingAs($admin)
            ->postJson('/admin/invitations', [
                'name' => 'Proposed Staff',
                'email' => 'proposed@plp.edu.ph',
                'role' => 'staff',
                'source' => 'EMS',
            ]);

        $response->assertStatus(201)
            ->assertJson([
                'status' => 'pending',
                'message' => 'Staff invitation submitted for Super Admin approval.',
            ]);

        $invitationId = $response->json('id');
        $this->assertDatabaseHas('account_invitations', [
            'id' => $invitationId,
            'status' => 'pending',
        ]);

        // Super Admin reviews and approves
        $reviewResp = $this->actingAs($superAdmin)
            ->postJson("/admin/invitations/{$invitationId}/review", [
                'approve' => true,
                'reason' => 'Approved for EMS operations',
            ]);

        $reviewResp->assertOk()
            ->assertJson([
                'status' => 'approved',
                'message' => 'Invitation decision recorded.',
            ]);

        $this->assertDatabaseHas('account_invitations', [
            'id' => $invitationId,
            'status' => 'approved',
            'reason' => 'Approved for EMS operations',
        ]);
    }

    public function test_send_invitation_with_log_mailer_is_graceful(): void
    {
        config(['mail.default' => 'log']);
        $superAdmin = $this->user('super_admin');
        $service = app(InvitationService::class);
        $id = $service->request($superAdmin, [
            'name' => 'Send Test',
            'email' => 'send-test@plp.edu.ph',
            'role' => 'staff',
            'source' => 'EMS',
        ]);

        $response = $this->actingAs($superAdmin)
            ->postJson("/admin/invitations/{$id}/send");

        $response->assertOk()
            ->assertJson([
                'status' => 'approved',
            ])
            ->assertJsonStructure(['status', 'message']);

        $this->assertDatabaseHas('account_invitations', [
            'id' => $id,
            'status' => 'approved',
        ]);
    }

    public function test_send_invitation_resend_throttling_within_60_seconds(): void
    {
        config(['mail.default' => 'smtp', 'mail.mailers.smtp.transport' => 'smtp']);
        Mail::fake();

        $superAdmin = $this->user('super_admin');
        $service = app(InvitationService::class);
        $id = $service->request($superAdmin, [
            'name' => 'Throttle Test',
            'email' => 'throttle-test@plp.edu.ph',
            'role' => 'staff',
            'source' => 'EMS',
        ]);

        // First send succeeds
        $this->actingAs($superAdmin)
            ->postJson("/admin/invitations/{$id}/send")
            ->assertOk()
            ->assertJson(['status' => 'delivered']);

        // Second send immediately should be throttled (429)
        $this->actingAs($superAdmin)
            ->postJson("/admin/invitations/{$id}/send")
            ->assertStatus(429);
    }

    public function test_invitation_json_form_and_acceptance(): void
    {
        $superAdmin = $this->user('super_admin');
        $service = app(InvitationService::class);
        $id = $service->request($superAdmin, [
            'name' => 'JSON Accept',
            'email' => 'json-accept@plp.edu.ph',
            'role' => 'staff',
            'source' => 'EMS',
        ]);

        $token = 'test-token-abcdef1234567890abcdef1234567890';
        DB::table('account_invitations')->where('id', $id)->update([
            'status' => 'sent',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);

        // GET invitation form as JSON
        $formResp = $this->getJson("/invitations/accept/{$token}");
        $formResp->assertOk()
            ->assertJson([
                'email' => 'json-accept@plp.edu.ph',
                'name' => 'JSON Accept',
                'role' => 'staff',
                'source' => 'EMS',
            ]);

        // POST acceptance with JSON
        $acceptResp = $this->postJson("/invitations/accept/{$token}", [
            'password' => 'ValidPass123!@#',
            'password_confirmation' => 'ValidPass123!@#',
        ]);

        $acceptResp->assertOk()
            ->assertJson([
                'message' => 'Account activated. Sign in with your chosen password.',
            ])
            ->assertJsonStructure([
                'message',
                'user' => ['id', 'name', 'email', 'role'],
            ]);

        $this->assertDatabaseHas('users', [
            'email' => 'json-accept@plp.edu.ph',
            'active' => true,
        ]);

        $this->assertDatabaseHas('account_invitations', [
            'id' => $id,
            'status' => 'accepted',
            'token_hash' => null,
        ]);

        // Attempting to reuse the token now fails because token_hash was wiped
        $this->getJson("/invitations/accept/{$token}")
            ->assertStatus(410);

        $this->postJson("/invitations/accept/{$token}", [
            'password' => 'ValidPass123!@#',
            'password_confirmation' => 'ValidPass123!@#',
        ])->assertStatus(404);
    }
}
