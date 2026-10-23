<?php

namespace Tests\Feature;

use App\Models\User;
use App\Policies\FolderPolicy;
use App\Services\Access;
use App\Services\WorkspaceRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WorkspaceRegistryTest extends TestCase
{
    use RefreshDatabase;

    public function test_registry_filters_membership_scope_and_lifecycle(): void
    {
        $staff = User::factory()->create(['role' => 'staff', 'source' => null, 'school_id' => 'PLP', 'campus' => 'Main']);
        DB::table('workspaces')->insert(['school_id' => 'PLP', 'campus' => 'Main', 'code' => 'LIBRARY', 'name' => 'Library', 'kind' => 'internal', 'status' => 'active']);
        $this->assertSame([], $staff->sources());
        WorkspaceRegistry::assign($staff, 'LIBRARY');
        $this->assertSame(['LIBRARY'], $staff->sources());
        $this->assertSame(['LIBRARY' => 'Library'], WorkspaceRegistry::labels($staff));
        DB::table('workspaces')->where('code', 'LIBRARY')->update(['name' => 'Library records']);
        $this->assertSame(['LIBRARY' => 'Library records'], WorkspaceRegistry::labels($staff));
        DB::table('workspaces')->where('code', 'LIBRARY')->update(['status' => 'archived']);
        $this->assertSame([], $staff->sources());
    }

    public function test_source_less_admin_fails_closed_and_staff_manages_owned_folders_only(): void
    {
        $admin = User::factory()->create(['role' => 'admin', 'source' => null, 'school_id' => 'PLP', 'campus' => 'Main']);
        $this->assertSame([], $admin->sources());
        $this->assertFalse(Access::manages($admin, (object) ['school_id' => 'PLP', 'campus' => 'Main', 'source' => 'EMS']));
        $staff = User::factory()->create(['role' => 'staff', 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main']);
        $folder = (object) ['school_id' => 'PLP', 'campus' => 'Main', 'source' => 'EMS', 'owner_id' => $staff->id];
        $policy = new FolderPolicy;
        $this->assertTrue($policy->update($staff, $folder));
        $folder->owner_id = $admin->id;
        $this->assertFalse($policy->update($staff, $folder));
        $staff->campus = 'Other';
        $this->assertFalse($staff->canAccessSource('EMS'));
    }

    public function test_super_admin_registry_still_respects_school_and_campus(): void
    {
        $actor = User::factory()->create(['role' => 'super_admin', 'school_id' => 'OTHER', 'campus' => 'Main']);
        $this->assertSame([], $actor->sources());
        $this->assertFalse(Access::manages($actor, (object) ['school_id' => 'PLP', 'campus' => 'Main', 'source' => 'EMS']));
    }
}
