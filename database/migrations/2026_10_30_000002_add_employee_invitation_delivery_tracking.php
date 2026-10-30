<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employee_invitations', function (Blueprint $table) {
            $table->string('delivery_status', 20)->default('not_sent');
            $table->timestamp('delivery_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            // Only application-defined codes, never provider exception messages.
            $table->string('delivery_error_code', 40)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('employee_invitations', fn (Blueprint $table) => $table->dropColumn([
            'delivery_status', 'delivery_attempted_at', 'sent_at', 'delivery_error_code',
        ]));
    }
};
