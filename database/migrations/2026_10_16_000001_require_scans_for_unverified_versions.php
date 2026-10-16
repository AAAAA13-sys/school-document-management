<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('document_versions')->where('scan', 'Not required')
            ->update(['scan' => 'Pending', 'status' => 'Awaiting scan']);
    }

    public function down(): void
    {
        // Security results cannot safely be reversed into a scanning bypass.
    }
};
