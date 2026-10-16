<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('folders', fn (Blueprint $t) => $t->string('color')->nullable());
    }

    public function down(): void
    {
        Schema::table('folders', fn (Blueprint $t) => $t->dropColumn('color'));
    }
};