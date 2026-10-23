<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $t) {
            $t->id();
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('code', 64);
            $t->string('name', 150);
            $t->string('kind', 12)->default('erp');
            $t->string('status', 12)->default('active');
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'code']);
        });
        Schema::create('workspace_memberships', function (Blueprint $t) {
            $t->id();
            $t->foreignId('workspace_id')->constrained('workspaces');
            $t->foreignId('user_id')->constrained('users');
            $t->timestamps();
            $t->unique(['workspace_id', 'user_id']);
        });
        foreach (['users', 'folders', 'documents', 'connectors', 'source_snapshots', 'history_receipts', 'audit_entries', 'integration_events'] as $table) {
            Schema::table($table, function (Blueprint $t) use ($table) {
                $column = $t->string('source', 64);
                if (in_array($table, ['users', 'audit_entries'], true)) {
                    $column->nullable();
                }
                $column->change();
            });
        }
        $scopes = DB::table('users')->select('school_id', 'campus')->distinct()->get();
        if ($scopes->isEmpty()) {
            $scopes->push((object) ['school_id' => 'PLP', 'campus' => 'Main']);
        }
        foreach ($scopes as $scope) {
            foreach (['EMS' => 'Employee Management', 'PMS' => 'Payroll Management', 'ES' => 'Enrollment', 'OAS' => 'Online Admission'] as $code => $name) {
                DB::table('workspaces')->insert(['school_id' => $scope->school_id, 'campus' => $scope->campus, 'code' => $code, 'name' => $name, 'kind' => 'erp', 'status' => 'active', 'created_at' => now(), 'updated_at' => now()]);
            }
            foreach (DB::table('users')->where('school_id', $scope->school_id)->where('campus', $scope->campus)->whereNotNull('source')->get() as $user) {
                $workspace = DB::table('workspaces')->where('school_id', $scope->school_id)->where('campus', $scope->campus)->where('code', $user->source)->value('id');
                if ($workspace) {
                    DB::table('workspace_memberships')->insert(['workspace_id' => $workspace, 'user_id' => $user->id, 'created_at' => now(), 'updated_at' => now()]);
                }
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('workspace_memberships');
        Schema::dropIfExists('workspaces');
        // Keep widened source columns: shrinking would destroy newly registered codes.
    }
};
