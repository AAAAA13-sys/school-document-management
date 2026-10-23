<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class LatestSddAuthorityTest extends TestCase
{
    use RefreshDatabase;

    public function test_super_admin_can_manage_documents_only_in_its_school_and_campus(): void
    {
        $actor = User::factory()->create(['active' => true, 'role' => 'super_admin', 'school_id' => 'PLP', 'campus' => 'Main']);
        $document = (object) ['id' => 'example', 'owner_id' => null, 'source' => 'EMS', 'school_id' => 'PLP', 'campus' => 'Main', 'classification' => 'Restricted'];
        $this->assertTrue(Gate::forUser($actor)->allows('documents.write'));
        $this->assertTrue(Gate::forUser($actor)->allows('document.edit', $document));
        $this->assertTrue(Gate::forUser($actor)->allows('document.manage', $document));
        $this->assertTrue(Gate::forUser($actor)->allows('document.classify', [$document, 'Internal']));
        $document->campus = 'Other';
        $this->assertFalse(Gate::forUser($actor)->allows('document.manage', $document));
    }
}
