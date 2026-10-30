<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\InvitationService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class EmployeeInvitationConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    protected function setUp(): void
    {
        parent::setUp();
        if (config('database.default') !== 'mysql') {
            $this->markTestSkipped('Use phpunit.mysql.xml for real invitation row-lock tests.');
        }
        $this->assertSame('dms_test', config('database.connections.mysql.database'));
        $this->assertNull(config('database.connections.mysql.url'), 'Do not use DB_URL for isolated concurrency tests.');
    }

    private function admin(): User
    {
        return User::factory()->create(['role' => 'admin', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    public function test_simultaneous_acceptance_creates_exactly_one_staff_account(): void
    {
        $issued = app(InvitationService::class)->create($this->admin(), ['email' => 'concurrent-invite@example.test', 'source' => 'EMS']);
        $request = ['operation' => 'accept', 'token' => $issued->revealToken(), 'attributes' => [
            'name' => 'Concurrent Staff', 'password' => 'SyntheticInvite!123', 'password_confirmation' => 'SyntheticInvite!123',
        ]];
        $responses = $this->runConcurrent([$request, $request, $request]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 422, 422], $statuses);
        $this->assertSame(1, User::where('email', 'concurrent-invite@example.test')->count());
        $user = User::where('email', 'concurrent-invite@example.test')->firstOrFail();
        $this->assertSame('staff', $user->role);
        $this->assertSame($user->id, $issued->invitation->fresh()->accepted_user_id);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'invitation.accepted')->count());
    }

    public function test_different_administrators_cannot_create_duplicate_pending_invitations_concurrently(): void
    {
        $requests = [];
        for ($i = 0; $i < 3; $i++) {
            $requests[] = ['operation' => 'create', 'actor' => $this->admin()->id, 'attributes' => ['email' => 'duplicate-invite@example.test', 'source' => 'EMS']];
        }
        $responses = $this->runConcurrent($requests);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 422, 422], $statuses);
        $this->assertDatabaseCount('employee_invitations', 1);
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'invitation.created')->count());
    }

    public function test_simultaneous_http_registration_accepts_only_once(): void
    {
        $issued = app(InvitationService::class)->create($this->admin(), ['email' => 'concurrent-http@example.test', 'source' => 'EMS']);
        $request = ['operation' => 'register', 'token' => $issued->revealToken(), 'attributes' => [
            'name' => 'Concurrent Staff', 'password' => 'SyntheticInvite!123', 'password_confirmation' => 'SyntheticInvite!123',
        ]];
        $responses = $this->runConcurrent([$request, $request, $request]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([302, 410, 410], $statuses);
        $this->assertSame(1, User::where('email', 'concurrent-http@example.test')->count());
        $this->assertSame(1, DB::table('audit_entries')->where('action', 'invitation.accepted')->count());
    }

    private function runConcurrent(#[\SensitiveParameter] array $requests): array
    {
        $connection = config('database.connections.mysql');
        $env = [
            'APP_ENV' => 'testing', 'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => 'dms_test',
            'DB_HOST' => $connection['host'], 'DB_PORT' => (string) $connection['port'],
            'DB_USERNAME' => $connection['username'], 'DB_PASSWORD' => $connection['password'],
            'CACHE_STORE' => 'array', 'BCRYPT_ROUNDS' => '4',
        ];
        $workers = [];
        $releaseAt = microtime(true) + 3;
        try {
            foreach ($requests as $request) {
                // Credentials travel through stdin, never command-line arguments or files.
                $process = new Process([PHP_BINARY, base_path('tests/support/invitation-worker.php')], base_path('tests/support'), $env,
                    json_encode($request + ['release_at' => $releaseAt], JSON_THROW_ON_ERROR), 30);
                $process->start();
                $workers[] = $process;
            }

            return array_map(function (Process $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), 'Invitation worker failed; no credentials are included in its output.');

                return json_decode($process->getOutput(), true, 512, JSON_THROW_ON_ERROR);
            }, $workers);
        } finally {
            foreach ($workers as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }

    public function test_rotation_and_acceptance_are_serialized(): void
    {
        $admin = $this->admin();
        $issued = app(InvitationService::class)->create($admin, ['email' => 'rotate-race@example.test', 'source' => 'EMS']);
        $responses = $this->runConcurrent([
            ['operation' => 'rotate', 'actor' => $admin->id, 'invitation' => $issued->invitation->id],
            ['operation' => 'accept', 'token' => $issued->revealToken(), 'attributes' => ['name' => 'Race Staff', 'password' => 'SyntheticInvite!123', 'password_confirmation' => 'SyntheticInvite!123']],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $current = $issued->invitation->fresh();
        if ($current->accepted_at !== null) {
            $this->assertSame(1, User::where('email', $current->email)->count());
        } else {
            $this->assertTrue($current->isPending());
            $this->assertTrue($current->token_hash !== $issued->invitation->token_hash);
            $this->assertSame(0, User::where('email', $current->email)->count());
        }
    }

    public function test_revocation_and_acceptance_are_serialized(): void
    {
        $admin = $this->admin();
        $issued = app(InvitationService::class)->create($admin, ['email' => 'revoke-race@example.test', 'source' => 'EMS']);
        $responses = $this->runConcurrent([
            ['operation' => 'revoke', 'actor' => $admin->id, 'invitation' => $issued->invitation->id],
            ['operation' => 'accept', 'token' => $issued->revealToken(), 'attributes' => ['name' => 'Race Staff', 'password' => 'SyntheticInvite!123', 'password_confirmation' => 'SyntheticInvite!123']],
        ]);
        $statuses = array_column($responses, 'status');
        sort($statuses);
        $this->assertSame([201, 422], $statuses);
        $current = $issued->invitation->fresh();
        $this->assertFalse($current->revoked_at !== null && $current->accepted_at !== null);
        $this->assertSame($current->accepted_at !== null ? 1 : 0, User::where('email', $current->email)->count());
        $this->assertNull($current->pending_email);
    }

    public function test_concurrent_replacement_of_expired_reservation_keeps_one_pending_email(): void
    {
        $issued = app(InvitationService::class)->create($this->admin(), ['email' => 'expired-race@example.test', 'source' => 'EMS']);
        $issued->invitation->forceFill(['expires_at' => now()->subMinute()])->save();
        $requests = [];
        for ($i = 0; $i < 3; $i++) {
            $requests[] = ['operation' => 'create', 'actor' => $this->admin()->id, 'attributes' => ['email' => 'expired-race@example.test', 'source' => 'EMS']];
        }
        $statuses = array_column($this->runConcurrent($requests), 'status');
        sort($statuses);
        $this->assertSame([201, 422, 422], $statuses);
        $this->assertDatabaseCount('employee_invitations', 2);
        $this->assertSame(1, DB::table('employee_invitations')->where('pending_email', 'expired-race@example.test')->count());
        $this->assertNull($issued->invitation->fresh()->pending_email);
    }
}
