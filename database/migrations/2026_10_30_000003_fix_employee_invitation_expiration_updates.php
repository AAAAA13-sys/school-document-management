<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB/MySQL with explicit_defaults_for_timestamp disabled implicitly
        // add ON UPDATE CURRENT_TIMESTAMP to the first non-null TIMESTAMP.
        // Delivery/status writes must never change an invitation's expiration.
        // DATETIME preserves the stored UTC values without that legacy behavior.
        Schema::table('employee_invitations', fn (Blueprint $table) => $table->dateTime('expires_at')->change());
    }

    public function down(): void
    {
        // Keep the safe type when reverting this migration; do not reintroduce
        // implicit expiry updates. Dropping the invitation table still works.
    }
};
