<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role',20)->default('staff')->change());
        DB::transaction(function () {
            DB::table('users')->where('role', 'admin')->update(['role'=>'super_admin','source'=>'DMS']);
            DB::table('users')->whereIn('role', ['hr','payroll','registrar'])->update(['role'=>'admin']);
            DB::table('users')->where('role', 'employee')->update(['role'=>'staff']);
        });
    }

    public function down(): void
    {
        Schema::table('users', fn (Blueprint $table) => $table->string('role',20)->default('employee')->change());
        DB::transaction(function () {
            DB::table('users')->where('role', 'staff')->update(['role'=>'employee']);
            DB::table('users')->where('role', 'admin')->where('source','EMS')->update(['role'=>'hr']);
            DB::table('users')->where('role', 'admin')->where('source','PMS')->update(['role'=>'payroll']);
            DB::table('users')->where('role', 'admin')->whereIn('source',['ES','OAS'])->update(['role'=>'registrar']);
            DB::table('users')->where('role', 'super_admin')->update(['role'=>'admin']);
        });
    }
};
