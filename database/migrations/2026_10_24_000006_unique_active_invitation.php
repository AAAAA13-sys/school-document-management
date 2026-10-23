<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('account_invitations', function (Blueprint $table) {
            $table->string('active_email')->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('account_invitations', function (Blueprint $table) {
            $table->dropUnique(['active_email']);
            $table->dropColumn(['active_email', 'sent_at']);
        });
    }
};
