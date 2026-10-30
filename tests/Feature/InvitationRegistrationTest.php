<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\User;
use App\Services\InvitationDeliveryService;
use App\Services\InvitationService;
use App\Services\IssuedInvitation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class InvitationRegistrationTest extends TestCase
{
    use RefreshDatabase;

    private function issue(array $attributes = []): IssuedInvitation
    {
        $issuer = User::factory()->create(['role' => 'admin', 'source' => 'PMS', 'school_id' => 'SYNTHETIC', 'campus' => 'Test', 'active' => true]);

        \Illuminate\Support\Facades\DB::table('workspaces')->insertOrIgnore(['code' => 'PMS', 'name' => 'PMS', 'kind' => 'erp', 'status' => 'active', 'school_id' => 'SYNTHETIC', 'campus' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        \Illuminate\Support\Facades\DB::table('workspaces')->insertOrIgnore(['code' => 'EMS', 'name' => 'EMS', 'kind' => 'erp', 'status' => 'active', 'school_id' => 'SYNTHETIC', 'campus' => 'Test', 'created_at' => now(), 'updated_at' => now()]);
        return app(InvitationService::class)->create($issuer, $attributes + ['email' => 'invited@example.test', 'source' => 'PMS']);
    }

    private function path(IssuedInvitation $issued): string
    {
        return '/invitations/'.$issued->revealToken();
    }

    private function credentials(array $overrides = []): array
    {
        return $overrides + ['email' => 'invited@example.test', 'name' => 'Synthetic Staff', 'password' => 'SyntheticRegister!123', 'password_confirmation' => 'SyntheticRegister!123'];
    }

    public function test_guest_registration_creates_scoped_active_staff_then_redirects_to_existing_login(): void
    {
        $issued = $this->issue();
        $response = $this->post($this->path($issued), $this->credentials())->assertRedirect('/login')->assertSessionHas('registration_complete', true);
        $this->assertGuest();
        $user = User::where('email', $issued->invitation->email)->firstOrFail();
        $this->assertSame('staff', $user->role);
        $this->assertSame('PMS', $user->source);
        $this->assertSame('SYNTHETIC', $user->school_id);
        $this->assertSame('Test', $user->campus);
        $this->assertTrue($user->active);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->credentials()['password'], $user->password));
        $this->assertSame('/workspaces/pms', $user->homePath());
        $invitation = $issued->invitation->fresh();
        $this->assertSame($user->id, $invitation->accepted_user_id);
        $this->assertNotNull($invitation->accepted_at);
        $this->assertNull($invitation->pending_email);
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.accepted', 'source' => 'PMS']);
        $this->assertDatabaseHas('audit_entries', ['action' => 'account.created', 'actor_id' => $user->id]);
        $this->followRedirects($response)->assertSee('Your account has been created.')->assertSee('Go to Login');
        $this->post('/login', ['email' => $user->email, 'password' => $this->credentials()['password']])->assertRedirect('/workspaces/pms');
        $this->assertAuthenticatedAs($user);
        $this->get('/workspaces/pms')->assertOk();
        $this->get('/workspaces/ems')->assertForbidden();
        $this->get('/admin/accounts')->assertForbidden();
    }

    public function test_get_displays_authoritative_readonly_email_and_never_consumes_or_saves_the_link(): void
    {
        $issued = $this->issue();
        $before = $issued->invitation->fresh()->getAttributes();
        for ($i = 0; $i < 2; $i++) {
            $response = $this->get($this->path($issued).'?email=forged@example.test')->assertOk();
            $response->assertSee('invited@example.test')->assertDontSee('forged@example.test')->assertSee('PMS');
            $html = $response->getContent();
            $this->assertMatchesRegularExpression('/<input[^>]*id="invited-email"[^>]*readonly/', $html);
            foreach (['role', 'school_id', 'campus', 'source', 'active'] as $field) {
                $this->assertStringNotContainsString('name="'.$field.'"', $html);
            }
        }
        $this->assertTrue($before === $issued->invitation->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 1);
        $this->assertFalse(str_contains(json_encode(session()->all()), $issued->revealToken()));
        $this->assertGuest();
    }

    public function test_email_tampering_never_changes_the_readonly_display_or_creates_an_account(): void
    {
        $issued = $this->issue();
        $this->post($this->path($issued), $this->credentials(['email' => 'forged@example.test']))
            ->assertStatus(422)->assertSee('invited@example.test')->assertDontSee('forged@example.test');
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($issued->invitation->fresh()->isPending());
        $this->assertArrayNotHasKey('_old_input', session()->all());
    }

    public function test_privileged_field_injection_is_rejected_and_never_used(): void
    {
        $issued = $this->issue();
        foreach (['role' => 'super_admin', 'school_id' => 'OTHER', 'campus' => 'Other', 'source' => 'EMS', 'active' => false] as $field => $value) {
            $this->post($this->path($issued), $this->credentials([$field => $value]))->assertStatus(422);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_password_policy_confirmation_and_hash_byte_limits_are_enforced_without_flashing_passwords(): void
    {
        $issued = $this->issue();
        $passwords = ['short', 'alllowercase123!', 'ALLUPPERCASE123!', 'MissingNumbers!!', 'MissingSymbols123', str_repeat('x', 72).'Aa1!', "HasNull\0Password!123"];
        foreach ($passwords as $password) {
            // Independent synthetic IP/token minute windows avoid testing the limiter here.
            $this->travel(61)->seconds();
            $response = $this->post($this->path($issued), $this->credentials(['password' => $password, 'password_confirmation' => $password]))->assertStatus(422);
            $this->assertFalse(str_contains($response->getContent(), $password));
            $this->assertFalse(str_contains(json_encode(session()->all()), $password));
        }
        $this->travel(61)->seconds();
        $this->post($this->path($issued), $this->credentials(['password_confirmation' => 'Mismatch!123']))->assertStatus(422)->assertSee('confirmation');
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_name_errors_are_accessible_and_redisplayed_name_is_escaped(): void
    {
        $issued = $this->issue();
        $this->post($this->path($issued), $this->credentials(['name' => '']))->assertStatus(422)->assertSee('aria-invalid="true"', false)->assertSee('registration-errors');
        $this->post($this->path($issued), $this->credentials(['name' => '<script>alert(1)</script>', 'password_confirmation' => 'Mismatch!123']))
            ->assertStatus(422)->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
    }

    public function test_invalid_tokens_get_safe_non_json_pages_even_with_debug_enabled(): void
    {
        config(['app.debug' => true]);
        foreach (['invalid-token', str_repeat('a', 64)] as $token) {
            $this->getJson('/invitations/'.$token)->assertNotFound()->assertHeader('Content-Type', 'text/html; charset=utf-8')->assertDontSee($token)->assertSee('This invitation is not available');
            $this->postJson('/invitations/'.$token, $this->credentials())->assertNotFound()->assertDontSee($token);
        }
    }

    public function test_expired_and_revoked_invitations_have_polished_states_without_private_information(): void
    {
        foreach (['expired', 'revoked'] as $state) {
            $issued = $this->issue(['email' => $state.'@example.test']);
            $issued->invitation->forceFill($state === 'expired' ? ['expires_at' => now()] : ['revoked_at' => now()])->save();
            $message = $state === 'expired' ? 'This invitation has expired' : 'This invitation is no longer available';
            foreach (['get', 'post'] as $method) {
                $response = $this->{$method}($this->path($issued), $method === 'post' ? $this->credentials() : [])->assertStatus(410)->assertSee($message)->assertSee('administrator');
                $response->assertDontSee($issued->invitation->email)->assertDontSee($issued->revealToken())->assertDontSee('/admin/employee-invitations');
            }
        }
        $this->assertSame(2, User::count());
    }

    public function test_used_token_cannot_create_overwrite_or_reactivate_an_account(): void
    {
        $issued = $this->issue();
        $this->post($this->path($issued), $this->credentials())->assertRedirect('/login');
        $user = User::where('email', $issued->invitation->email)->firstOrFail();
        $user->active = false;
        $user->save();
        $before = $user->fresh()->getAttributes();
        $this->get($this->path($issued))->assertStatus(410)->assertSee('already been used')->assertSee('Go to Login');
        $this->post($this->path($issued), $this->credentials())->assertStatus(410);
        $this->assertTrue($before === $user->fresh()->getAttributes());
        $this->assertDatabaseCount('users', 2);
    }

    public function test_existing_email_conflicts_do_not_disclose_or_overwrite_accounts(): void
    {
        $issued = $this->issue();
        $existing = User::factory()->create(['email' => $issued->invitation->email, 'active' => false]);
        $before = $existing->fresh()->getAttributes();
        $this->get($this->path($issued))->assertStatus(409)->assertSee('This invitation is not available')->assertDontSee($existing->email);
        $this->post($this->path($issued), $this->credentials())->assertStatus(409)->assertDontSee($existing->email);
        $this->assertTrue($before === $existing->fresh()->getAttributes());
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_issuer_losing_role_scope_or_active_status_blocks_registration(): void
    {
        $issued = $this->issue();
        $issuer = $issued->invitation->inviter;
        foreach ([['active' => false], ['role' => 'staff'], ['source' => 'EMS'], ['campus' => 'Other'], ['school_id' => 'OTHER']] as $change) {
            $issuer->forceFill($change)->save();
            $this->get($this->path($issued))->assertNotFound()->assertDontSee($issuer->email);
            $this->post($this->path($issued), $this->credentials())->assertNotFound();
            $issuer->forceFill(['active' => true, 'role' => 'admin', 'source' => 'PMS', 'school_id' => 'SYNTHETIC', 'campus' => 'Test'])->save();
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_stored_non_staff_role_or_invalid_source_is_rejected(): void
    {
        $issued = $this->issue();
        foreach ([['role' => 'super_admin'], ['source' => 'DMS']] as $change) {
            $issued->invitation->forceFill($change)->save();
            $this->post($this->path($issued), $this->credentials())->assertNotFound();
            $issued->invitation->forceFill(['role' => 'staff', 'source' => 'PMS'])->save();
        }
        $this->assertDatabaseCount('users', 1);
    }

    public function test_expiry_is_revalidated_on_post_after_form_was_displayed(): void
    {
        $issued = $this->issue();
        $this->get($this->path($issued))->assertOk();
        $this->travel(24)->hours();
        $this->post($this->path($issued), $this->credentials())->assertStatus(410)->assertSee('expired');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_resend_rotation_invalidates_old_http_link(): void
    {
        $issued = $this->issue();
        $rotated = app(InvitationService::class)->rotateToken($issued->invitation->inviter, $issued->invitation->id);
        $this->get($this->path($issued))->assertNotFound();
        $this->post($this->path($issued), $this->credentials())->assertNotFound();
        $this->get($this->path($rotated))->assertOk();
        $this->post($this->path($rotated), $this->credentials())->assertRedirect('/login');
    }

    public function test_privacy_headers_and_local_assets_apply_without_external_asset_overrides(): void
    {
        config(['app.asset_url' => 'https://external.example.test']);
        $issued = $this->issue();
        foreach ([$this->path($issued), '/invitations/invalid'] as $path) {
            $response = $this->get($path);
            $response->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertHeader('X-Frame-Options', 'DENY');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertStringContainsString("script-src 'self'", $response->headers->get('Content-Security-Policy'));
            $response->assertDontSee('external.example.test')->assertDontSee('/js/dms.js');
        }
    }

    public function test_database_failure_is_sanitized_and_does_not_log_token_urls(): void
    {
        $issued = $this->issue();
        Log::spy();
        $this->mock(InvitationService::class, function ($mock) {
            $mock->shouldReceive('validateToken')->andThrow(new \RuntimeException('Synthetic private database details.'));
            $mock->shouldReceive('accept')->andThrow(new \RuntimeException('Synthetic private database details.'));
        });
        $this->get($this->path($issued))->assertStatus(503)->assertSee('temporarily unavailable')->assertDontSee('Synthetic private')->assertDontSee($issued->revealToken());
        $this->post($this->path($issued), $this->credentials())->assertStatus(503)->assertDontSee($this->credentials()['password']);
        Log::shouldNotHaveReceived('error');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_csrf_failure_and_throttling_are_safe_and_non_consuming(): void
    {
        $issued = $this->issue();
        $this->app->instance('env', 'local');
        $this->post($this->path($issued), $this->credentials())->assertStatus(419)->assertSee('session has expired')->assertHeader('Referrer-Policy', 'no-referrer')->assertDontSee($issued->revealToken());
        $this->app->instance('env', 'testing');
        for ($i = 0; $i < 5; $i++) {
            $this->post($this->path($issued), $this->credentials(['password_confirmation' => 'Mismatch!123']))->assertStatus(422);
        }
        $this->post($this->path($issued), $this->credentials())->assertStatus(429)->assertSee('Please wait')->assertHeader('Retry-After')->assertDontSee($issued->revealToken());
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_unhandled_token_route_errors_do_not_log_or_return_request_urls(): void
    {
        config(['app.debug' => true]);
        Log::spy();
        Route::get('/invitations/{token}', fn () => throw new \RuntimeException('Synthetic private route failure.'));
        $this->getJson('/invitations/internal-failure')->assertStatus(503)->assertSee('temporarily unavailable')
            ->assertDontSee('internal-failure')->assertDontSee('Synthetic private')->assertHeader('Referrer-Policy', 'no-referrer');
        Log::shouldNotHaveReceived('error');
    }

    public function test_smtp_mail_link_reaches_real_registration_routes_but_default_gate_stays_disabled(): void
    {
        Mail::fake();
        $issued = $this->issue();
        $delivery = app(InvitationDeliveryService::class);
        $this->assertFalse(config('invitations.dispatch_enabled'));
        $this->assertNotNull($delivery->unavailableReason());
        $this->assertTrue(Route::has('invitations.accept'));
        $this->assertTrue(Route::has('invitations.register'));
        config(['invitations.dispatch_enabled' => true, 'mail.default' => 'smtp', 'app.url' => 'https://dms.example.test']);
        $invitation = $delivery->resend($issued->invitation->inviter, $issued->invitation->id);
        $mail = Mail::sent(EmployeeInvitationMail::class)->last();
        $url = $mail->content()->with['registrationUrl'];
        $this->assertTrue(str_starts_with($url, 'https://dms.example.test/invitations/'));
        $this->get(parse_url($url, PHP_URL_PATH))->assertOk();
        $this->post(parse_url($url, PHP_URL_PATH), $this->credentials())->assertRedirect('/login');
        $this->assertNotNull($invitation->fresh()->accepted_at);
        Mail::assertNothingQueued();
    }

    public function test_signed_in_session_is_preserved_without_creating_another_account(): void
    {
        $issued = $this->issue();
        $issuer = $issued->invitation->inviter;
        $this->actingAs($issuer)->get($this->path($issued))->assertOk()->assertSee('already signed in');
        $this->post($this->path($issued), $this->credentials())->assertStatus(409)->assertSee('already signed in');
        $this->assertAuthenticatedAs($issuer);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_unrestricted_registration_remains_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->post('/register', $this->credentials())->assertNotFound();
    }
}
