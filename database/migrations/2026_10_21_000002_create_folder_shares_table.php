<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folder_shares', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('folder_id')->constrained('folders');
            $t->foreignId('recipient_id')->constrained('users');
            $t->string('permission', 12);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['folder_id', 'recipient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folder_shares');
    }
};
