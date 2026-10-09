<?php
namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SharingPermissionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_named_grants_allow_only_the_selected_operations_and_preserve_ownership(): void
    {
        Storage::fake('local');
        $owner = User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']);
        $this->actingAs($owner);
        $file=$this->withHeader('Idempotency-Key','share-owner-upload')->post('/workspace/documents', ['title'=>'Shared evidence','subject'=>'Owner','reference'=>'REF-1','source'=>'Enrollment','category'=>'Academic record','classification'=>'Confidential','file'=>UploadedFile::fake()->createWithContent('proof.txt','Synthetic shared record.')], ['Accept'=>'application/json'])->assertCreated()->json();
        DB::table('document_versions')->where('id',$file['version_id'])->update(['scan'=>'Clean']);
        foreach (['viewer','commenter','editor'] as $permission) {
            $recipient=User::factory()->create(['role'=>'employee','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Main campus']);
            $this->actingAs($owner);
            $grant=$this->postJson('/workspace/drive/'.$file['document_id'].'/shares', ['email'=>$recipient->email,'permission'=>$permission,'expires_at'=>now()->addDay()->toIso8601String()])->assertCreated()->json();
            $base='/workspace/shared/'.$grant['id'];
            $this->actingAs($recipient)->getJson('/workspace/shared-with-me')->assertOk()->assertJsonCount(1,'files.data')->assertJsonPath('files.data.0.permission',$permission);
            $this->getJson('/workspace/documents/'.$file['document_id'])->assertNotFound();
            $this->get($grant['url'])->assertOk();
            $this->getJson($base.'/comments')->assertOk();
            $comment=$this->postJson($base.'/comments',['body'=>'Useful evidence']);
            $permission==='viewer' ? $comment->assertForbidden() : $comment->assertCreated();
            $doc=DB::table('documents')->where('id',$file['document_id'])->first();
            $rename=$this->patchJson($base.'/document',['title'=>'Updated name','revision'=>$doc->revision]);
            if ($permission==='editor') {
                $rename->assertOk();
                $this->patchJson($base.'/document',['title'=>'Stale name','revision'=>$doc->revision])->assertConflict();
                $this->withHeader('Idempotency-Key','shared-new-version')->post($base.'/versions', ['revision'=>$doc->revision+1,'document_id'=>\Illuminate\Support\Str::uuid(),'source'=>'Payroll Management','file'=>UploadedFile::fake()->createWithContent('new.txt','A new synthetic version.')], ['Accept'=>'application/json'])->assertCreated();
                $this->assertDatabaseHas('documents',['id'=>$doc->id,'owner_user_id'=>$owner->id,'source'=>'Enrollment']);
                $this->assertDatabaseCount('document_versions',2);
                $this->get($grant['url'])->assertOk()->assertDownload('proof.txt');
                $this->assertDatabaseHas('document_shares', ['id'=>$grant['id'], 'version_id'=>$file['version_id']]);
            } else {
                $rename->assertForbidden();
                $this->postJson($base.'/versions',[])->assertForbidden();
            }
            $this->postJson('/workspace/drive/'.$file['document_id'].'/action',['action'=>'trash','revision'=>1])->assertNotFound();
            $this->postJson('/workspace/drive/'.$file['document_id'].'/shares',['email'=>$owner->email,'expires_at'=>now()->addDay()->toIso8601String()])->assertNotFound();
            $this->actingAs($owner)->deleteJson('/workspace/shares/'.$grant['id'])->assertOk();
            $this->actingAs($recipient)->getJson('/workspace/shared-with-me')->assertJsonCount(0,'files.data');
            $this->postJson($base.'/comments',['body'=>'After revocation'])->assertNotFound();
        }
    }

    public function test_grants_reject_foreign_campus_invalid_permissions_and_expired_access(): void
    {
        Storage::fake('local');
        config(['dms.demo_password' => 'Test-only-password-2026']);
        $this->seed(\Database\Seeders\DemoSeeder::class);
        $owner=User::where('role','admin')->first();
        $recipient=User::factory()->create(['role'=>'student','active'=>true,'school_id'=>'DEMO-SCHOOL','campus'=>'Elsewhere']);
        $doc=DB::table('documents')->first();
        $this->actingAs($owner)->postJson('/workspace/drive/'.$doc->id.'/shares',['email'=>$recipient->email,'permission'=>'editor','expires_at'=>now()->addDay()->toIso8601String()])->assertForbidden();
        $recipient->forceFill(['campus'=>'Main campus'])->save();
        $this->postJson('/workspace/drive/'.$doc->id.'/shares',['email'=>$recipient->email,'permission'=>'owner','expires_at'=>now()->addDay()->toIso8601String()])->assertUnprocessable();
        $grant=$this->postJson('/workspace/drive/'.$doc->id.'/shares',['email'=>$recipient->email,'permission'=>'commenter','expires_at'=>now()->addHour()->toIso8601String()])->assertCreated()->json();
        $this->travel(2)->hours();
        $this->actingAs($recipient)->getJson('/workspace/shared-with-me')->assertJsonCount(0,'files.data');
        $this->postJson($grant['url'].'/comments',['body'=>'Expired'])->assertNotFound();
    }
}
