<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('reviewed_by')->nullable()->constrained('users');
            $table->string('name');
            $table->string('email');
            $table->string('role');
            $table->string('source')->nullable();
            $table->string('school_id');
            $table->string('campus');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->string('token_hash', 64)->nullable()->unique();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_invitations');
    }
};
