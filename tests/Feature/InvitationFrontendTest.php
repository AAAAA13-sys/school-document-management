<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\HierarchicalInvitationService as InvitationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class InvitationFrontendTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role = 'super_admin', string $source = 'EMS'): User
    {
        return User::factory()->create([
            'role' => $role,
            'source' => $source,
            'school_id' => 'PLP',
            'campus' => 'Main',
            'active' => true,
        ]);
    }

    public function test_super_admin_invitations_page_renders_dms_layout_and_tabs(): void
    {
        $superAdmin = $this->user('super_admin');
        $service = app(InvitationService::class);
        $admin = $this->user('admin', 'EMS');

        // Create a pending invitation
        $pendingId = $service->request($admin, ['name' => 'Pending Staff', 'email' => 'pending@plp.edu.ph', 'role' => 'staff', 'source' => 'EMS']);

        $response = $this->actingAs($superAdmin)->get('/admin/invitations');

        $response->assertOk();
        $response->assertSee('Administration · Account invitations');
        $response->assertSee('Staff directory');
        $response->assertSee('Invitations');
        $response->assertSee('Account requests');
        $response->assertSee('Pending Staff');
        $response->assertSee('pending@plp.edu.ph');
        $response->assertSee('modal-review-'.$pendingId);
        $response->assertSee('Decision reason');
        $response->assertSee('invitation-new');
        $response->assertSee('table-zebra');
    }

    public function test_admin_invitations_page_renders_only_own_requests(): void
    {
        $admin1 = $this->user('admin', 'EMS');
        $admin2 = $this->user('admin', 'PMS');
        $service = app(InvitationService::class);

        $service->request($admin1, ['name' => 'Staff Admin1', 'email' => 'staff1@plp.edu.ph', 'role' => 'staff', 'source' => 'EMS']);
        $service->request($admin2, ['name' => 'Staff Admin2', 'email' => 'staff2@plp.edu.ph', 'role' => 'staff', 'source' => 'PMS']);

        $response = $this->actingAs($admin1)->get('/admin/invitations');

        $response->assertOk();
        $response->assertSee('Staff Admin1');
        $response->assertDontSee('Staff Admin2');
    }

    public function test_valid_token_renders_styled_password_page(): void
    {
        $superAdmin = $this->user('super_admin');
        $service = app(InvitationService::class);

        $id = $service->request($superAdmin, ['name' => 'Invited Person', 'email' => 'invited@plp.edu.ph', 'role' => 'staff', 'source' => 'EMS']);
        $token = 'valid-test-token-12345';
        DB::table('account_invitations')->where('id', $id)->update([
            'status' => 'sent',
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHours(24),
        ]);

        $response = $this->get('/invitations/accept/'.$token);

        $response->assertOk();
        $response->assertSee('login-page');
        $response->assertSee('Activate your account');
        $response->assertSee('invited@plp.edu.ph');
        $response->assertSee('data-password-policy');
        $response->assertSee('Back to sign in');
    }

    public function test_invalid_or_expired_token_renders_custom_410_page(): void
    {
        $response = $this->get('/invitations/accept/non-existent-token');

        $response->assertStatus(410);
        $response->assertSee('login-page');
        $response->assertSee('Invitation expired or invalid');
        $response->assertSee('Return to sign in');
        $response->assertSee('/login');
    }
}
