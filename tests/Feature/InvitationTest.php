<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvitationService;
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
}
