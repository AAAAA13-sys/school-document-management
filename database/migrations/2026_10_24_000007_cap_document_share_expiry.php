<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $limit = now()->addDays(30);
        DB::table('shares')->whereNull('revoked_at')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $limit))
            ->update(['expires_at' => $limit, 'updated_at' => now()]);
    }

    public function down(): void
    {
        // A rollback must not silently extend a recipient's authorized access.
    }
};
