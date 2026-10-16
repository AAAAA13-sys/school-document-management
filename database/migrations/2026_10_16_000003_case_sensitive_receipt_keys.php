<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }
        DB::statement('ALTER TABLE history_receipts MODIFY event_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL');
        DB::statement('ALTER TABLE request_receipts MODIFY request_key VARCHAR(100) COLLATE utf8mb4_bin NOT NULL');
    }

    public function down(): void
    { /* Keep binary identities: folding existing keys could lose receipts. */
    }
};
