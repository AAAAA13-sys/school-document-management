<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', fn (Blueprint $t) => $t->string('actor_key')->default('legacy:unknown'));
    }

    public function down(): void
    {
        Schema::table('document_versions', fn (Blueprint $t) => $t->dropColumn('actor_key'));
    }
};
