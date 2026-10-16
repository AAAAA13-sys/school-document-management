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
        // MariaDB with implicit TIMESTAMP defaults otherwise changes upload time on every scan update.
        DB::statement('ALTER TABLE document_versions MODIFY created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP');
    }

    public function down(): void
    { /* Never restore automatic mutation of an immutable version's upload time. */
    }
};
