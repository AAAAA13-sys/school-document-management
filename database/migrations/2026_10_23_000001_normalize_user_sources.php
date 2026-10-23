<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Existing installations may have applied the earlier non-nullable source
        // column. Keep the correction additive for those databases.
        Schema::table('users', fn (Blueprint $table) => $table->string('source', 3)->nullable()->default(null)->change());

        DB::transaction(function () {
            DB::table('users')->whereIn('role', ['employee', 'teacher', 'hr'])
                ->update(['role' => 'staff', 'source' => 'EMS']);
            DB::table('users')->where('role', 'payroll')
                ->update(['role' => 'staff', 'source' => 'PMS']);
            DB::table('users')->where('role', 'super_admin')->update(['source' => null]);

            // Legacy admin/registrar rows are not rewritten here. The old mapping
            // migration is corrected for fresh databases, while already-affected
            // installations are listed as a follow-up rather than guessed at.
        });
    }

    public function down(): void
    {
        // This migration only normalizes role/source data; it is intentionally
        // irreversible because the original legacy role cannot be recovered.
    }
};
