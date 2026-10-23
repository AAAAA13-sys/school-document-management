<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Classification existed before access rules were enforced. New rows must
        // start at the least restrictive level; existing explicit values are kept.
        Schema::table('documents', fn (Blueprint $table) => $table->string('classification', 20)->default('Internal')->change());

        DB::table('documents')->whereNull('classification')->update(['classification' => 'Internal']);
    }

    public function down(): void
    {
        Schema::table('documents', fn (Blueprint $table) => $table->string('classification', 20)->default('Confidential')->change());
    }
};
