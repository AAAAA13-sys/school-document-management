<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AccountChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AccountChangeTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        return User::factory()->create(['role' => $role, 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'active' => true]);
    }

    public function test_admin_suspension_requires_approval_and_invalidates_sessions_once(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff');
        $reviewer = $this->user('super_admin');
        $initialVersion = $target->fresh()->auth_version;
        $service = app(AccountChangeService::class);
        $data = $target->only(['name', 'email', 'role', 'source']);
        $data['active'] = false;
        $this->assertSame('pending', $service->submit($admin, (string) $target->id, $data));
        $this->assertTrue($target->fresh()->active);
        $id = DB::table('account_changes')->value('id');
        $service->decide($reviewer, $id, true, 'Approved suspension');
        $this->assertFalse($target->fresh()->active);
        $this->assertSame($initialVersion + 1, $target->fresh()->auth_version);
        $this->expectException(HttpException::class);
        $service->decide($reviewer, $id, true, 'Replay');
    }

    public function test_changed_account_cannot_be_overwritten_by_stale_proposal(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff');
        $reviewer = $this->user('super_admin');
        $initialVersion = $target->fresh()->auth_version;
        $service = app(AccountChangeService::class);
        $data = $target->only(['name', 'email', 'role', 'source', 'active']);
        $data['name'] = 'Proposed';
        $service->submit($admin, (string) $target->id, $data);
        $target->update(['name' => 'Changed meanwhile']);
        try {
            $service->decide($reviewer, DB::table('account_changes')->value('id'), true, 'Approve');
            $this->fail('Stale change applied');
        } catch (HttpException $e) {
            $this->assertSame(409, $e->getStatusCode());
        }
        $this->assertSame('Changed meanwhile', $target->fresh()->name);
    }

    public function test_requester_authority_is_rechecked_and_staff_cannot_decide(): void
    {
        $admin = $this->user('admin');
        $target = $this->user('staff');
        $reviewer = $this->user('super_admin');
        $initialVersion = $target->fresh()->auth_version;
        $service = app(AccountChangeService::class);
        $data = $target->only(['name', 'email', 'role', 'source', 'active']);
        $service->submit($admin, (string) $target->id, $data);
        $id = DB::table('account_changes')->value('id');
        try {
            $service->decide($target, $id, true, 'Invalid reviewer');
            $this->fail('Staff approved');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $admin->update(['active' => false]);
        $this->expectException(HttpException::class);
        $service->decide($reviewer, $id, true, 'No longer authorized');
    }

    public function test_super_admin_direct_changes_cannot_deactivate_self(): void
    {
        $actor = $this->user('super_admin');
        $data = $actor->only(['name', 'email', 'role', 'source']);
        $data['active'] = false;
        $this->expectException(HttpException::class);
        app(AccountChangeService::class)->submit($actor, (string) $actor->id, $data);
    }
}
