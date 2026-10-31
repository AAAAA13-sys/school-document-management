<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $scopes = DB::table('workspaces')->select('school_id', 'campus')->distinct()->get();
        if ($scopes->isEmpty()) {
            $scopes = collect([(object) ['school_id' => 'PLP', 'campus' => 'Main']]);
        }

        foreach ($scopes as $scope) {
            DB::table('workspaces')->updateOrInsert(
                [
                    'school_id' => $scope->school_id,
                    'campus' => $scope->campus,
                    'code' => 'DMS',
                ],
                [
                    'name' => 'Document Management System',
                    'kind' => 'erp',
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            );
        }
    }

    public function down(): void
    {
        DB::table('workspaces')->where('code', 'DMS')->delete();
    }
};

