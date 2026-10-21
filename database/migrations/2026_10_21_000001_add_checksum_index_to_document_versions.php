<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('document_versions', function (Blueprint $t) {
            // Advisory duplicate detection and its locking read filter by checksum.
            $t->index('checksum');
        });
    }

    public function down(): void
    {
        Schema::table('document_versions', function (Blueprint $t) {
            $t->dropIndex(['checksum']);
        });
    }
};
