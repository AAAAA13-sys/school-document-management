<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('permission')->default('viewer');
            $t->foreignUuid('folder_id')->constrained('folders')->cascadeOnDelete();
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('recipient_id')->constrained('users');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_shares');
    }
};