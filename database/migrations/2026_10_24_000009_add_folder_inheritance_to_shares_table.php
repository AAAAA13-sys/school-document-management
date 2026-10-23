<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shares', function (Blueprint $t) {
            $t->boolean('folder_inheritance')->default(false)->after('revoked_at');
        });
    }

    public function down(): void
    {
        Schema::table('shares', function (Blueprint $t) {
            $t->dropColumn('folder_inheritance');
        });
    }
};
