<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role', 20)->default('staff');
            $table->string('source', 3);
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            // A nullable global email reservation closes concurrent-create races.
            // Expired reservations are released lazily; history keeps its email.
            $table->string('pending_email')->nullable()->unique();
            $table->timestamps();
            $table->index(['school_id', 'campus', 'source'], 'invitations_scope_index');
            $table->index(['email', 'expires_at']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_invitations');
    }
};
