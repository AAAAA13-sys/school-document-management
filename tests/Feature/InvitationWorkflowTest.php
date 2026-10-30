<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class InvitationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_administrator_to_email_to_registration_to_scoped_login_workflow(): void
    {
        // Only this isolated fake transport test enables the in-memory config.
        // No environment file, real mail transport or deployment flag is changed.
        Mail::fake();
        config(['invitations.dispatch_enabled' => true, 'mail.default' => 'smtp', 'app.url' => 'https://dms.example.test']);
        $admin = User::factory()->create(['role' => 'admin', 'source' => 'ES', 'school_id' => 'WORKFLOW', 'campus' => 'Test', 'active' => true]);
        \Illuminate\Support\Facades\DB::table('workspaces')->insertOrIgnore(['code' => 'ES', 'name' => 'ES', 'kind' => 'erp', 'status' => 'active', 'school_id' => 'WORKFLOW', 'campus' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('workspaces')->insertOrIgnore(['code' => 'EMS', 'name' => 'EMS', 'kind' => 'erp', 'status' => 'active', 'school_id' => 'WORKFLOW', 'campus' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($admin)->get('/admin/accounts')->assertOk()->assertSee('Staff invitations')->assertSee('Send invitation');
        $this->post('/admin/employee-invitations', ['email' => 'workflow@example.test', 'source' => 'ES'])->assertRedirect('/admin/accounts')->assertSessionHasNoErrors();
        $invitation = EmployeeInvitation::firstOrFail();
        $this->assertSame('sent', $invitation->delivery_status);
        $this->assertTrue($invitation->isPending());
        Mail::assertSent(EmployeeInvitationMail::class, fn ($mail) => $mail->hasTo('workflow@example.test'));
        $mail = Mail::sent(EmployeeInvitationMail::class)->sole();
        $this->assertStringContainsString('Complete Registration', $mail->render());
        $path = parse_url($mail->content()->with['registrationUrl'], PHP_URL_PATH);
        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();
        $this->get($path)->assertOk()->assertSee('workflow@example.test')->assertSee('readonly')->assertSee('ES');
        $this->assertNull($invitation->fresh()->accepted_at);
        $this->post($path, [
            'email' => 'workflow@example.test', 'name' => 'Workflow Staff',
            'password' => 'SyntheticWorkflow!123', 'password_confirmation' => 'SyntheticWorkflow!123',
        ])->assertRedirect('/login')->assertSessionHas('registration_complete', true);
        $this->assertGuest();
        $user = User::where('email', 'workflow@example.test')->firstOrFail();
        $this->assertSame('staff', $user->role);
        $this->assertSame('WORKFLOW', $user->school_id);
        $this->assertSame('Test', $user->campus);
        $this->assertSame('ES', $user->source);
        $this->assertTrue($user->active);
        $this->assertNull($user->email_verified_at);
        $this->assertSame($user->id, $invitation->fresh()->accepted_user_id);
        $this->assertNull($invitation->fresh()->pending_email);
        $this->get($path)->assertStatus(410)->assertSee('already been used');
        $this->post('/login', ['email' => 'workflow@example.test', 'password' => 'SyntheticWorkflow!123'])->assertRedirect('/workspaces/es');
        $this->assertAuthenticatedAs($user);
        $this->get('/workspaces/es')->assertOk();
        $this->get('/workspaces/ems')->assertForbidden();
        $this->get('/admin')->assertForbidden();
        $this->get('/register')->assertNotFound();
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('users', 2);
    }
}
