<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['documents', 'folders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->foreignId('owner_user_id')->nullable()->constrained('users')->restrictOnDelete());
        }
    }

    public function down(): void
    {
        foreach (['documents', 'folders'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropConstrainedForeignId('owner_user_id'));
        }
    }
};
