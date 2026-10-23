<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role', 20)->default('staff')->change());

        DB::transaction(function () {
            // Legacy staff equivalents keep their existing access by becoming the
            // canonical staff role with the source their old role implied.
            DB::table('users')->whereIn('role', ['employee', 'teacher', 'hr'])
                ->update(['role' => 'staff', 'source' => 'EMS']);
            DB::table('users')->where('role', 'payroll')
                ->update(['role' => 'staff', 'source' => 'PMS']);

            // Super Admin is deliberately source-less. Legacy admin and registrar
            // are intentionally left alone until their access rules are agreed.
            DB::table('users')->where('role', 'super_admin')->update(['source' => null]);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role', 20)->default('employee')->change());

        // The role/source mapping is not reversed: the original legacy role cannot
        // be recovered after records have been edited in the new hierarchy.
    }
};
