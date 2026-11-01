<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('connectors', 'plain_token')) {
            Schema::table('connectors', function (Blueprint $table) {
                $table->string('plain_token', 255)->nullable()->after('token_hash');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('connectors', 'plain_token')) {
            Schema::table('connectors', function (Blueprint $table) {
                $table->dropColumn('plain_token');
            });
        }
    }
};

