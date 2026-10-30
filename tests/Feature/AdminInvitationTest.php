<?php

namespace Tests\Feature;

use App\Mail\EmployeeInvitationMail;
use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\InvitationDeliveryService;
use App\Services\InvitationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Testing\Fakes\MailFake;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class AdminInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config(['invitations.dispatch_enabled' => true, 'mail.default' => 'smtp', 'app.url' => 'https://dms.example.test']);
        Mail::fake();
    }

    private function actor(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
        foreach (['EMS', 'PMS', 'ES', 'OAS'] as $code) {
            DB::table('workspaces')->insertOrIgnore(['code' => $code, 'name' => $code, 'kind' => 'erp', 'status' => 'active', 'school_id' => $user->school_id, 'campus' => $user->campus, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $user;
    }

    private function payload(array $attributes = []): array
    {
        return $attributes + ['email' => 'invited@example.test', 'source' => 'EMS'];
    }

    private function sentToken(): string
    {
        $mail = Mail::sent(EmployeeInvitationMail::class)->last();
        $url = $mail->content()->with['registrationUrl'];

        return basename(parse_url($url, PHP_URL_PATH));
    }

    public function test_admin_and_super_admin_can_send_staff_invitations_without_creating_accounts(): void
    {
        foreach (['admin', 'super_admin'] as $role) {
            $actor = $this->actor(['role' => $role]);
            $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload(['email' => $role.'@example.test']))
                ->assertRedirect('/admin/accounts')->assertSessionHasNoErrors()->assertSessionHas('status');
            $invitation = EmployeeInvitation::where('email', $role.'@example.test')->firstOrFail();
            $this->assertSame('staff', $invitation->role);
            $this->assertSame('PLP', $invitation->school_id);
            $this->assertSame('Main', $invitation->campus);
            $this->assertSame('sent', $invitation->delivery_status);
            $this->assertNotNull($invitation->sent_at);
            $this->assertNull($invitation->accepted_at);
        }
        Mail::assertSent(EmployeeInvitationMail::class, 2);
        Mail::assertNothingQueued();
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.email_submitted', 'source' => 'EMS']);
    }

    public function test_guests_staff_and_inactive_administrators_cannot_send(): void
    {
        $this->post('/admin/employee-invitations', $this->payload())->assertRedirect('/login');
        $this->actingAs($this->actor(['role' => 'staff']))->post('/admin/employee-invitations', $this->payload())->assertForbidden();
        $this->actingAs($this->actor(['active' => false]))->post('/admin/employee-invitations', $this->payload())->assertRedirect('/login');
        $this->assertDatabaseCount('employee_invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_invalid_email_and_privileged_fields_are_rejected(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload(['email' => 'invalid']))->assertSessionHasErrors('email');
        foreach (['role' => 'super_admin', 'school_id' => 'OTHER', 'campus' => 'Other'] as $field => $value) {
            $this->post('/admin/employee-invitations', $this->payload([$field => $value]))->assertSessionHasErrors($field);
        }
        $this->assertDatabaseCount('employee_invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_duplicate_pending_invitations_and_existing_accounts_do_not_send(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload())->assertRedirect('/admin/accounts')->assertSessionHasNoErrors();
        $this->post('/admin/employee-invitations', $this->payload(['email' => ' INVITED@EXAMPLE.TEST ']))->assertSessionHasErrors('email');
        $this->post('/admin/employee-invitations', $this->payload(['email' => $actor->email]))->assertSessionHasErrors('email');
        $this->assertDatabaseCount('employee_invitations', 1);
        Mail::assertSent(EmployeeInvitationMail::class, 1);
    }

    public function test_source_authority_and_legacy_admin_scope_are_preserved(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload(['source' => 'PMS']))->assertForbidden();
        $legacy = $this->actor(['source' => null]);
        $this->actingAs($legacy)->post('/admin/employee-invitations', $this->payload(['source' => 'OAS']))->assertForbidden();
        $this->assertDatabaseCount('employee_invitations', 0);
    }

    public function test_resend_rotates_token_and_revocation_requires_confirmation(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload());
        $invitation = EmployeeInvitation::firstOrFail();
        $oldToken = $this->sentToken();
        $this->post('/admin/employee-invitations/'.$invitation->id.'/resend')->assertSessionHasNoErrors();
        $newToken = $this->sentToken();
        $this->assertDatabaseCount('employee_invitations', 1);
        $this->assertTrue(hash_equals($invitation->fresh()->token_hash, hash('sha256', $newToken)), 'The latest email must match the persisted invitation credential.');
        $this->assertTrue($oldToken !== $newToken, 'Resending must replace the credential.');
        try {
            app(InvitationService::class)->validateToken($oldToken);
            $this->fail('The old link was still valid.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('invitation', $error->errors());
        }
        $this->assertTrue(app(InvitationService::class)->validateToken($newToken)->isPending());
        $this->post('/admin/employee-invitations/'.$invitation->id.'/revoke')->assertSessionHasErrors('confirm_revoke');
        $this->post('/admin/employee-invitations/'.$invitation->id.'/revoke', ['confirm_revoke' => '1'])->assertSessionHasNoErrors();
        $this->assertNotNull($invitation->fresh()->revoked_at);
        $this->assertSame('sent', $invitation->fresh()->delivery_status);
        $this->post('/admin/employee-invitations/'.$invitation->id.'/resend')->assertSessionHasErrors('invitation');
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.revoked']);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_other_sources_schools_and_campuses_cannot_manage_an_invitation(): void
    {
        $owner = $this->actor();
        $issued = app(InvitationService::class)->create($owner, $this->payload());
        foreach ([['source' => 'PMS'], ['school_id' => 'OTHER'], ['campus' => 'Other']] as $attributes) {
            $actor = $this->actor($attributes);
            $this->actingAs($actor)->post('/admin/employee-invitations/'.$issued->invitation->id.'/resend')->assertNotFound();
            $this->post('/admin/employee-invitations/'.$issued->invitation->id.'/revoke', ['confirm_revoke' => '1'])->assertNotFound();
        }
        $staff = $this->actor(['role' => 'staff']);
        $this->actingAs($staff)->post('/admin/employee-invitations/'.$issued->invitation->id.'/resend')->assertForbidden();
        $this->post('/admin/employee-invitations/'.$issued->invitation->id.'/revoke', ['confirm_revoke' => '1'])->assertForbidden();
        Mail::assertNothingSent();
    }

    public function test_smtp_failures_are_tracked_without_logging_provider_details(): void
    {
        Log::spy();
        $fake = new class(Mail::getFacadeRoot()->manager) extends MailFake
        {
            public function send($view, array $data = [], $callback = null)
            {
                throw new \RuntimeException('Synthetic provider error with sensitive message content.');
            }
        };
        Mail::swap($fake);
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload())->assertSessionHasErrors('invitation_delivery');
        $invitation = EmployeeInvitation::firstOrFail();
        $this->assertSame('failed', $invitation->delivery_status);
        $this->assertSame('smtp_send_failed', $invitation->delivery_error_code);
        $this->assertSame('Pending', $invitation->statusLabel());
        $this->assertNull($invitation->sent_at);
        $this->assertFalse(str_contains(json_encode($invitation), 'Synthetic provider error'));
        $this->assertFalse(str_contains(json_encode(DB::table('audit_entries')->get()), 'Synthetic provider error'));
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.delivery_failed']);
        foreach (['error', 'warning', 'debug', 'log'] as $method) {
            Log::shouldNotHaveReceived($method);
        }
        $failedHash = $invitation->token_hash;
        Mail::fake();
        $this->post('/admin/employee-invitations/'.$invitation->id.'/resend')->assertSessionHasNoErrors();
        $this->assertTrue($invitation->fresh()->token_hash !== $failedHash);
        $this->assertSame('sent', $invitation->fresh()->delivery_status);
        $this->assertNull($invitation->fresh()->delivery_error_code);
    }

    public function test_failed_resend_invalidates_the_previously_sent_link(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload());
        $oldToken = $this->sentToken();
        $invitation = EmployeeInvitation::firstOrFail();
        Mail::swap(new class(Mail::getFacadeRoot()->manager) extends MailFake
        {
            public function send($view, array $data = [], $callback = null)
            {
                throw new \RuntimeException('Synthetic SMTP outage.');
            }
        });
        $this->post('/admin/employee-invitations/'.$invitation->id.'/resend')->assertSessionHasErrors('invitation_delivery');
        $this->assertSame('failed', $invitation->fresh()->delivery_status);
        $this->expectException(ValidationException::class);
        app(InvitationService::class)->validateToken($oldToken);
    }

    public function test_creation_and_resend_share_a_rate_limit(): void
    {
        $actor = $this->actor();
        $issued = app(InvitationService::class)->create($actor, $this->payload());
        $this->actingAs($actor);
        for ($i = 0; $i < 5; $i++) {
            $this->post('/admin/employee-invitations/'.$issued->invitation->id.'/resend')->assertSessionHasNoErrors();
        }
        $this->post('/admin/employee-invitations', $this->payload(['email' => 'limited@example.test']))->assertStatus(429);
        Mail::assertSent(EmployeeInvitationMail::class, 5);
    }

    public function test_listing_is_scoped_and_shows_state_separately_from_delivery(): void
    {
        $actor = $this->actor();
        $own = app(InvitationService::class)->create($actor, $this->payload(['email' => 'visible@example.test']));
        $own->invitation->forceFill(['delivery_status' => 'failed'])->save();
        foreach ([['source' => 'PMS'], ['school_id' => 'OTHER'], ['campus' => 'Other']] as $index => $attributes) {
            $other = $this->actor($attributes);
            app(InvitationService::class)->create($other, ['email' => 'hidden'.$index.'@example.test', 'source' => $other->source]);
        }
        $response = $this->actingAs($actor)->get('/admin/accounts')->assertOk()->assertSee('Staff invitations')->assertSee('visible@example.test')->assertSee('Delivery Failed')->assertSee('Pending');
        for ($i = 0; $i < 3; $i++) {
            $response->assertDontSee('hidden'.$i.'@example.test');
        }
        $this->assertFalse(str_contains($response->getContent(), $own->invitation->token_hash));
        $super = $this->actor(['role' => 'super_admin']);
        $this->actingAs($super)->get('/admin/accounts')->assertOk()->assertSee('hidden0@example.test')->assertDontSee('hidden1@example.test')->assertDontSee('hidden2@example.test');
    }

    public function test_history_displays_all_lifecycle_states_and_paginated_results(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        foreach (['accepted', 'expired', 'revoked'] as $state) {
            $issued = app(InvitationService::class)->create($actor, $this->payload(['email' => $state.'@example.test']));
            $issued->invitation->forceFill(match ($state) {
                'accepted' => ['accepted_at' => now(), 'pending_email' => null],
                'expired' => ['expires_at' => now()->subMinute()],
                'revoked' => ['revoked_at' => now(), 'pending_email' => null],
            })->save();
        }
        $this->get('/admin/accounts')->assertOk()->assertSee('Accepted')->assertSee('Expired')->assertSee('Revoked');
        for ($i = 0; $i < 15; $i++) {
            app(InvitationService::class)->create($actor, $this->payload(['email' => 'page'.$i.'@example.test']));
        }
        $response = $this->get('/admin/accounts')->assertOk();
        $this->assertSame(15, $response->viewData('invitations')->count());
        $response->assertSee('invitations_page=2', false);
    }

    public function test_email_html_text_and_expiration_use_existing_branding(): void
    {
        $expires = CarbonImmutable::parse('2030-01-02 03:04:00', 'UTC');
        $mail = new EmployeeInvitationMail('employee@example.test', $expires, 'https://example.invalid/invitations/preview-not-valid');
        $html = $mail->render();
        $text = view('emails.employee-invitation-text', $mail->content()->with)->render();
        foreach (['PLP Documents', 'employee@example.test', 'Complete Registration', 'January 2, 2030, 03:04 UTC', 'used once', 'did not expect', 'administrator'] as $content) {
            $this->assertStringContainsString($content, $html);
        }
        $this->assertStringContainsString('https://example.invalid/invitations/preview-not-valid', $text);
        $this->assertStringContainsString('used once', $text);
        $this->assertSame(['type' => 'employee_invitation'], $mail->__debugInfo());
        $this->assertFalse(str_contains(json_encode($mail), 'preview-not-valid'));
        $this->expectException(\LogicException::class);
        serialize($mail);
    }

    public function test_preview_is_authorized_synthetic_and_sends_nothing_even_with_gate_disabled(): void
    {
        config(['invitations.dispatch_enabled' => false]);
        $this->actingAs($this->actor())->get('/admin/employee-invitations/preview')->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertSee('employee@example.test')->assertSee('example.invalid');
        $this->assertDatabaseCount('employee_invitations', 0);
        Mail::assertNothingSent();
        $this->actingAs($this->actor(['role' => 'staff']))->get('/admin/employee-invitations/preview')->assertForbidden();
    }

    public function test_default_gate_blocks_creation_and_resend_without_rotating_tokens(): void
    {
        $actor = $this->actor();
        $issued = app(InvitationService::class)->create($actor, $this->payload());
        $hash = $issued->invitation->token_hash;
        config(['invitations.dispatch_enabled' => false]);
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload(['email' => 'disabled@example.test']))->assertSessionHasErrors('invitation_delivery');
        $this->post('/admin/employee-invitations/'.$issued->invitation->id.'/resend')->assertSessionHasErrors('invitation_delivery');
        $this->assertTrue($hash === $issued->invitation->fresh()->token_hash);
        $this->assertDatabaseCount('employee_invitations', 1);
        Mail::assertNothingSent();
        $this->get('/admin/accounts')->assertSee('Invitation sending is disabled')->assertSee('Preview email');
    }

    public function test_log_array_and_failover_transports_are_never_counted_as_delivery(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor);
        foreach (['log', 'array', 'failover'] as $mailer) {
            config(['mail.default' => $mailer]);
            $this->post('/admin/employee-invitations', $this->payload())->assertSessionHasErrors('invitation_delivery');
        }
        $this->assertDatabaseCount('employee_invitations', 0);
        Mail::assertNothingSent();
    }

    public function test_real_dispatch_requires_both_future_routes_and_a_secure_app_url(): void
    {
        Mail::swap(Mail::getFacadeRoot()->manager);
        $delivery = app(InvitationDeliveryService::class);
        $this->assertNull($delivery->unavailableReason());
        // Temporarily remove one name from this test's router to verify the guard.
        Route::getRoutes()->getByName('invitations.register')->name('test.registration.unavailable');
        Route::getRoutes()->refreshNameLookups();
        $this->assertStringContainsString('destination', $delivery->unavailableReason());
        Route::post('/invitations/{token}', fn () => abort(404))->name('invitations.register');
        Route::getRoutes()->refreshNameLookups();
        $this->assertNull($delivery->unavailableReason());
        config(['app.url' => 'http://public.example.test']);
        $this->assertStringContainsString('HTTPS', $delivery->unavailableReason());
        config(['app.url' => 'http://127.0.0.1:8011']);
        $this->assertNull($delivery->unavailableReason());
        config(['app.url' => 'https://dms.example.test?redirect=bad']);
        $this->assertStringContainsString('HTTPS', $delivery->unavailableReason());
    }

    public function test_real_mailer_builds_multipart_mail_and_tracks_transport_failure_without_network_access(): void
    {
        // Exercise Laravel's actual Mailable/Mailer pipeline with a local test transport.
        // These route stubs exist only in this test, not routes/web.php.
        Route::get('/invitations/{token}', fn () => abort(404))->name('invitations.accept');
        Route::post('/invitations/{token}', fn () => abort(404))->name('invitations.register');
        Route::getRoutes()->refreshNameLookups();
        Mail::swap(Mail::getFacadeRoot()->manager);
        $transport = new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                $email = $message->getOriginalMessage();
                if (! $email instanceof Email || ! $email->getHtmlBody() || ! $email->getTextBody()) {
                    throw new \LogicException('Expected multipart invitation email.');
                }
            }

            public function __toString(): string
            {
                return 'smtp-test';
            }
        };
        Mail::mailer('smtp')->setSymfonyTransport($transport);
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/employee-invitations', $this->payload())->assertSessionHasNoErrors();
        $invitation = EmployeeInvitation::firstOrFail();
        $this->assertSame('sent', $invitation->delivery_status);

        // A framework listener may cancel sending; null must not count as success.
        Event::listen(MessageSending::class, fn () => false);
        $this->post('/admin/employee-invitations/'.$invitation->id.'/resend')->assertSessionHasErrors('invitation_delivery');
        $this->assertSame('failed', $invitation->fresh()->delivery_status);
    }

    public function test_sent_url_uses_configured_app_url_instead_of_request_host(): void
    {
        config(['app.url' => 'https://dms.example.test/school']);
        $this->actingAs($this->actor())->withServerVariables(['HTTP_HOST' => 'untrusted.example.test'])->post('/admin/employee-invitations', $this->payload())->assertSessionHasNoErrors();
        Mail::assertSent(EmployeeInvitationMail::class, fn ($mail) => str_starts_with($mail->content()->with['registrationUrl'], 'https://dms.example.test/school/invitations/'));
    }

    public function test_delivery_lock_prevents_simultaneous_resend_rotation(): void
    {
        $actor = $this->actor();
        $issued = app(InvitationService::class)->create($actor, $this->payload());
        $hash = $issued->invitation->token_hash;
        $lock = Cache::lock('invitation-delivery:'.$issued->invitation->id, 120);
        $lock->get();
        try {
            $this->actingAs($actor)->post('/admin/employee-invitations/'.$issued->invitation->id.'/resend')->assertSessionHasErrors('invitation_delivery');
            $this->assertTrue($hash === $issued->invitation->fresh()->token_hash);
            Mail::assertNothingSent();
        } finally {
            $lock->release();
        }
    }

    public function test_existing_direct_account_creation_remains_available_and_public_registration_absent(): void
    {
        $actor = $this->actor();
        $this->actingAs($actor)->post('/admin/accounts', [
            'name' => 'Direct Staff', 'email' => 'direct@example.test',
            'role' => 'staff', 'source' => 'EMS', 'active' => 1,
        ])->assertSessionHasNoErrors();
        $this->assertDatabaseMissing('users', ['email' => 'direct@example.test']);
        $this->assertDatabaseHas('account_invitations', ['email' => 'direct@example.test', 'role' => 'staff', 'status' => 'pending']);
        $this->get('/admin/accounts')->assertOk()->assertSee('Invite staff')->assertSee('Staff invitations');
        $this->get('/register')->assertNotFound();
        $this->get('/invitations/unusable')->assertNotFound();
        $this->assertTrue(Route::has('invitations.accept'));
        $this->assertTrue(Route::has('invitations.register'));
        Mail::assertNothingSent();
    }

    public function test_invitation_posts_use_csrf_protection(): void
    {
        // Enable the real framework CSRF check, normally skipped by test requests.
        $this->app->instance('env', 'local');
        $this->actingAs($this->actor())->post('/admin/employee-invitations', $this->payload())->assertStatus(419);
        Mail::assertNothingSent();
    }
}
