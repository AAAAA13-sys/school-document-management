<?php

namespace Tests\Feature;

use App\Models\EmployeeInvitation;
use App\Models\User;
use App\Services\InvitationService;
use App\Services\IssuedInvitation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class EmployeeInvitationTest extends TestCase
{
    use RefreshDatabase;

    private function admin(array $attributes = []): User
    {
        $user = User::factory()->create($attributes + ['role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
        foreach (['EMS', 'PMS', 'ES', 'OAS'] as $code) {
            DB::table('workspaces')->insertOrIgnore(['code' => $code, 'name' => $code, 'kind' => 'erp', 'status' => 'active', 'school_id' => $user->school_id, 'campus' => $user->campus, 'created_at' => now(), 'updated_at' => now()]);
        }
        return $user;
    }

    private function issue(?User $actor = null, array $attributes = []): IssuedInvitation
    {
        return app(InvitationService::class)->create($actor ?? $this->admin(), $attributes + ['email' => 'invited@example.test', 'source' => 'EMS']);
    }

    private function registration(array $attributes = []): array
    {
        return $attributes + ['name' => 'Synthetic Staff', 'password' => 'SyntheticInvite!123', 'password_confirmation' => 'SyntheticInvite!123'];
    }

    private function assertInvalid(callable $operation, string $field): void
    {
        try {
            $operation();
            $this->fail('Expected validation rejection.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey($field, $exception->errors());
        }
    }

    public function test_creation_normalizes_email_inherits_scope_and_returns_only_one_time_credentials(): void
    {
        $this->freezeTime();
        $admin = $this->admin();
        $issued = $this->issue($admin, ['email' => '  INVITED@EXAMPLE.TEST ', 'source' => ' ems ']);
        $invitation = $issued->invitation;
        $this->assertTrue(Str::isUuid($invitation->id));
        $this->assertMatchesRegularExpression('/^[a-f0-9]{64}$/', $issued->revealToken());
        $this->assertSame(hash('sha256', $issued->revealToken()), $invitation->token_hash);
        $this->assertSame('invited@example.test', $invitation->email);
        $this->assertSame('staff', $invitation->role);
        $this->assertSame($admin->school_id, $invitation->school_id);
        $this->assertSame($admin->campus, $invitation->campus);
        $this->assertSame(now()->addHours(24)->getTimestamp(), $invitation->expires_at->getTimestamp());
        $this->assertTrue($invitation->inviter->is($admin));
        $this->assertNull($invitation->acceptedUser);
        $this->assertDatabaseCount('users', 1);
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.created', 'source' => 'EMS', 'actor_id' => $admin->id]);
    }

    public function test_credentials_are_hidden_from_json_debug_output_and_audit(): void
    {
        $issued = $this->issue();
        $serialized = json_encode([$issued, $issued->invitation, $issued->__debugInfo(), $issued->invitation->__debugInfo(), DB::table('audit_entries')->get()]);
        $this->assertStringNotContainsString($issued->revealToken(), $serialized);
        $this->assertStringNotContainsString($issued->invitation->token_hash, $serialized);
        $this->assertStringNotContainsString('token_hash', $serialized);
        $this->assertStringNotContainsString('pending_email', $serialized);
    }

    public function test_delivery_and_other_updates_never_change_invitation_expiration(): void
    {
        $issued = $this->issue();
        $expiresAt = $issued->invitation->expires_at->getTimestamp();
        $issued->invitation->forceFill(['delivery_status' => 'sending', 'delivery_attempted_at' => now()])->save();
        $issued->invitation->forceFill(['delivery_status' => 'sent', 'sent_at' => now()])->save();
        $this->assertSame($expiresAt, $issued->invitation->fresh()->expires_at->getTimestamp());
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_model_rejects_mass_assignment_of_invitation_state(): void
    {
        $this->expectException(MassAssignmentException::class);
        EmployeeInvitation::create(['email' => 'forged@example.test', 'role' => 'super_admin']);
    }

    public function test_issued_credentials_cannot_be_persisted_using_php_serialization(): void
    {
        $issued = $this->issue();
        $this->expectException(\LogicException::class);
        serialize($issued);
    }

    public function test_staff_and_inactive_or_stale_administrators_cannot_create(): void
    {
        foreach ([['role' => 'staff'], ['active' => false], ['role' => 'registrar']] as $attributes) {
            $actor = $this->admin($attributes);
            try {
                $this->issue($actor);
                $this->fail('Unauthorized actor created an invitation.');
            } catch (AuthorizationException) {
                $this->assertDatabaseCount('employee_invitations', 0);
            }
        }
        $stale = $this->admin();
        User::whereKey($stale->id)->update(['active' => false]);
        $this->expectException(AuthorizationException::class);
        $this->issue($stale);
    }

    public function test_admin_cannot_invite_to_another_source(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->issue($this->admin(), ['source' => 'PMS']);
    }

    public function test_super_admin_and_legacy_source_less_admin_preserve_source_authority(): void
    {
        foreach (['super_admin'] as $role) {
            $actor = $this->admin(['role' => $role, 'source' => null]);
            foreach (['EMS', 'PMS', 'ES', 'OAS'] as $source) {
                $issued = $this->issue($actor, ['source' => $source, 'email' => strtolower($role.$source).'@example.test']);
                $this->assertSame($source, $issued->invitation->source);
                $this->assertSame('staff', $issued->invitation->role);
            }
        }
    }

    public function test_source_less_admin_cannot_issue_staff_invitations(): void
    {
        $this->expectException(AuthorizationException::class);
        $this->issue($this->admin(['source' => null]), ['source' => 'OAS']);
    }

    public function test_create_rejects_privileged_fields_and_invalid_sources(): void
    {
        foreach (['role' => 'super_admin', 'school_id' => 'OTHER', 'campus' => 'Other', 'active' => false, 'expires_at' => '2099-01-01'] as $field => $value) {
            $this->assertInvalid(fn () => $this->issue(null, [$field => $value]), $field);
        }
        $this->assertInvalid(fn () => $this->issue(null, ['source' => 'DMS']), 'source');
        $this->assertDatabaseCount('employee_invitations', 0);
    }

    public function test_duplicate_pending_email_is_rejected_globally_and_case_insensitively(): void
    {
        $this->issue();
        $other = $this->admin(['school_id' => 'OTHER', 'campus' => 'Other']);
        $this->assertInvalid(fn () => $this->issue($other, ['email' => ' INVITED@EXAMPLE.TEST ']), 'email');
        $this->assertDatabaseCount('employee_invitations', 1);
    }

    public function test_expired_reservation_can_be_replaced_but_old_token_remains_expired(): void
    {
        $first = $this->issue();
        $this->travel(24)->hours();
        $this->assertTrue($first->invitation->hasExpired());
        $this->assertInvalid(fn () => app(InvitationService::class)->validateToken($first->revealToken()), 'invitation');
        $this->assertInvalid(fn () => app(InvitationService::class)->accept($first->revealToken(), $this->registration()), 'invitation');
        $second = $this->issue();
        $this->assertNotSame($first->invitation->id, $second->invitation->id);
        $this->assertNull($first->invitation->fresh()->pending_email);
    }

    public function test_validation_is_read_only_and_acceptance_is_atomic_and_single_use(): void
    {
        $issued = $this->issue();
        $service = app(InvitationService::class);
        for ($i = 0; $i < 2; $i++) {
            $this->assertTrue($service->validateToken($issued->revealToken())->isPending());
        }
        $this->assertDatabaseCount('users', 1);
        $user = $service->accept($issued->revealToken(), $this->registration(['email' => ' INVITED@EXAMPLE.TEST ']));
        $this->assertSame('staff', $user->role);
        $this->assertSame('invited@example.test', $user->email);
        $this->assertSame('EMS', $user->source);
        $this->assertSame('PLP', $user->school_id);
        $this->assertSame('Main', $user->campus);
        $this->assertTrue($user->active);
        $this->assertNull($user->email_verified_at);
        $this->assertTrue(Hash::check($this->registration()['password'], $user->password));
        $invitation = $issued->invitation->fresh();
        $this->assertNotNull($invitation->accepted_at);
        $this->assertTrue($invitation->acceptedUser->is($user));
        $this->assertNull($invitation->pending_email);
        $this->assertInvalid(fn () => $service->accept($issued->revealToken(), $this->registration()), 'invitation');
        $this->assertInvalid(fn () => $service->validateToken($issued->revealToken()), 'invitation');
        $this->assertInvalid(fn () => $service->rotateToken($invitation->inviter, $invitation->id), 'invitation');
        $this->assertInvalid(fn () => $service->revoke($invitation->inviter, $invitation->id), 'invitation');
        $this->assertDatabaseCount('users', 2);
        $this->assertDatabaseHas('audit_entries', ['action' => 'invitation.accepted', 'source' => 'EMS']);
    }

    public function test_email_and_role_scope_tampering_cannot_create_a_user(): void
    {
        $issued = $this->issue();
        foreach (['email' => 'attacker@example.test', 'role' => 'super_admin', 'source' => 'PMS', 'school_id' => 'OTHER', 'campus' => 'Other', 'active' => false] as $field => $value) {
            $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration([$field => $value])), $field);
        }
        $this->assertDatabaseCount('users', 1);
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_weak_passwords_and_mismatched_confirmation_do_not_consume_invitation(): void
    {
        $issued = $this->issue();
        foreach (['short', 'alllowercase123!', 'ALLUPPERCASE123!', 'MissingNumbers!!', 'MissingSymbols123'] as $password) {
            $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration(['password' => $password, 'password_confirmation' => $password])), 'password');
        }
        $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration(['password_confirmation' => 'Mismatch!123'])), 'password');
        $this->assertTrue($issued->invitation->fresh()->isPending());
        $this->assertDatabaseCount('users', 1);
    }

    public function test_a_precomputed_hash_cannot_bypass_the_password_policy(): void
    {
        $issued = $this->issue();
        $submitted = Hash::make('weak');
        $user = app(InvitationService::class)->accept($issued->revealToken(), $this->registration([
            'password' => $submitted, 'password_confirmation' => $submitted,
        ]));
        $this->assertTrue(Hash::check($submitted, $user->password));
        $this->assertFalse(Hash::check('weak', $user->password));
    }

    public function test_revocation_is_idempotent_releases_email_and_blocks_acceptance_and_rotation(): void
    {
        $admin = $this->admin();
        $issued = $this->issue($admin);
        $service = app(InvitationService::class);
        $service->revoke($admin, $issued->invitation->id);
        $service->revoke($admin, $issued->invitation->id);
        $this->assertNotNull($issued->invitation->fresh()->revoked_at);
        $this->assertNull($issued->invitation->fresh()->pending_email);
        $this->assertInvalid(fn () => $service->accept($issued->revealToken(), $this->registration()), 'invitation');
        $this->assertInvalid(fn () => $service->rotateToken($admin, $issued->invitation->id), 'invitation');
        $this->assertTrue($this->issue($admin)->invitation->isPending());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'invitation.revoked')->count());
    }

    public function test_rotation_invalidates_old_token_and_refreshes_expiry_without_sending_mail(): void
    {
        config(['invitations.expires_in_hours' => 2]);
        $this->freezeTime();
        $admin = $this->admin();
        $issued = $this->issue($admin);
        $this->travel(3)->hours();
        $rotated = app(InvitationService::class)->rotateToken($admin, $issued->invitation->id);
        $this->assertSame(now()->addHours(2)->getTimestamp(), $rotated->invitation->expires_at->getTimestamp());
        $this->assertNotSame($issued->revealToken(), $rotated->revealToken());
        $this->assertInvalid(fn () => app(InvitationService::class)->validateToken($issued->revealToken()), 'invitation');
        $this->assertTrue(app(InvitationService::class)->validateToken($rotated->revealToken())->isPending());
    }

    public function test_expired_invitation_cannot_be_rotated_over_a_new_pending_invitation(): void
    {
        $admin = $this->admin();
        $first = $this->issue($admin);
        $this->travel(25)->hours();
        $second = $this->issue($admin);
        $this->assertInvalid(fn () => app(InvitationService::class)->rotateToken($admin, $first->invitation->id), 'email');
        $this->assertTrue(app(InvitationService::class)->validateToken($second->revealToken())->isPending());
    }

    public function test_other_sources_schools_and_campuses_cannot_manage_an_invitation(): void
    {
        $issued = $this->issue();
        foreach ([['source' => 'PMS'], ['school_id' => 'OTHER'], ['campus' => 'Other']] as $attributes) {
            $other = $this->admin($attributes);
            foreach (['revoke', 'rotateToken'] as $operation) {
                try {
                    app(InvitationService::class)->{$operation}($other, $issued->invitation->id);
                    $this->fail('Out-of-scope invitation was accessible.');
                } catch (HttpException $exception) {
                    $this->assertSame(404, $exception->getStatusCode());
                }
            }
        }
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_staff_cannot_manage_invitations_and_super_admin_can_manage_same_scope(): void
    {
        $issued = $this->issue();
        $staff = $this->admin(['role' => 'staff']);
        foreach (['revoke', 'rotateToken'] as $operation) {
            try {
                app(InvitationService::class)->{$operation}($staff, $issued->invitation->id);
                $this->fail('Staff managed an invitation.');
            } catch (AuthorizationException) {
                $this->assertTrue($issued->invitation->fresh()->isPending());
            }
        }
        $super = $this->admin(['role' => 'super_admin', 'source' => null]);
        $rotated = app(InvitationService::class)->rotateToken($super, $issued->invitation->id);
        $this->assertTrue(app(InvitationService::class)->validateToken($rotated->revealToken())->isPending());
        $this->assertNotNull(app(InvitationService::class)->revoke($super, $issued->invitation->id)->revoked_at);
    }

    public function test_existing_active_or_inactive_accounts_cannot_be_invited(): void
    {
        foreach ([true, false] as $active) {
            $account = $this->admin(['role' => 'staff', 'active' => $active]);
            $this->assertInvalid(fn () => $this->issue(null, ['email' => $account->email]), 'email');
        }
        $this->assertDatabaseCount('employee_invitations', 0);
    }

    public function test_legacy_mixed_case_existing_email_is_also_rejected(): void
    {
        $this->admin(['email' => 'LEGACY@EXAMPLE.TEST']);
        $this->assertInvalid(fn () => $this->issue(null, ['email' => 'legacy@example.test']), 'email');
        $this->assertDatabaseCount('employee_invitations', 0);
    }

    public function test_storage_errors_never_propagate_query_bindings_or_a_previous_exception(): void
    {
        $issued = $this->issue();
        DB::listen(function ($query) use ($issued) {
            if (str_contains($query->sql, 'employee_invitations')) {
                throw new QueryException('testing', 'select token_hash', [$issued->invitation->token_hash], new \RuntimeException('Synthetic storage failure.'));
            }
        });
        try {
            app(InvitationService::class)->validateToken($issued->revealToken());
            $this->fail('Expected a sanitized storage failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Invitation storage is unavailable.', $exception->getMessage());
            $this->assertNull($exception->getPrevious());
        }
    }

    public function test_account_created_after_invitation_blocks_acceptance_and_resend_without_overwrite(): void
    {
        $admin = $this->admin();
        $issued = $this->issue($admin);
        $existing = $this->admin(['email' => $issued->invitation->email, 'role' => 'staff', 'active' => false]);
        $before = $existing->fresh()->getAttributes();
        $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration()), 'email');
        $this->assertInvalid(fn () => app(InvitationService::class)->rotateToken($admin, $issued->invitation->id), 'email');
        $this->assertTrue($before === $existing->fresh()->getAttributes(), 'The existing account must remain unchanged.');
        $this->assertTrue($issued->invitation->fresh()->isPending());
    }

    public function test_issuer_authority_is_revalidated_and_stored_role_corruption_is_rejected(): void
    {
        $admin = $this->admin();
        $issued = $this->issue($admin);
        foreach ([['active' => false], ['role' => 'staff'], ['source' => 'PMS'], ['school_id' => 'OTHER'], ['campus' => 'Other']] as $attributes) {
            $admin->forceFill($attributes)->save();
            $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration()), 'invitation');
            $admin->forceFill(['active' => true, 'role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main'])->save();
        }
        $issued->invitation->forceFill(['role' => 'super_admin'])->save();
        $this->assertInvalid(fn () => app(InvitationService::class)->accept($issued->revealToken(), $this->registration()), 'invitation');
        $this->assertDatabaseCount('users', 1);
    }

    public function test_invalid_tokens_and_bad_expiration_config_fail_closed(): void
    {
        foreach (['', 'invalid', str_repeat('a', 64)] as $token) {
            $this->assertInvalid(fn () => app(InvitationService::class)->validateToken($token), 'invitation');
        }
        config(['invitations.expires_in_hours' => 0]);
        try {
            $this->issue();
            $this->fail('Invalid expiry configuration was accepted.');
        } catch (\LogicException) {
            $this->assertDatabaseCount('employee_invitations', 0);
        }
    }

    public function test_audit_failure_rolls_back_both_account_creation_and_acceptance(): void
    {
        $issued = $this->issue();
        $service = app(InvitationService::class);
        DB::listen(function ($query) {
            if (str_contains($query->sql, 'insert into') && str_contains($query->sql, 'audit_entries')) {
                throw new \RuntimeException('Synthetic audit failure.');
            }
        });
        try {
            $service->accept($issued->revealToken(), $this->registration());
            $this->fail('Expected simulated audit failure.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic audit failure.', $exception->getMessage());
            $this->assertDatabaseCount('users', 1);
            $this->assertTrue($issued->invitation->fresh()->isPending());
        }
    }
}
