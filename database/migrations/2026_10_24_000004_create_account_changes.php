<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('account_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requester_id')->constrained('users');
            $table->foreignId('account_id')->constrained('users');
            $table->foreignId('reviewer_id')->nullable()->constrained('users');
            $table->string('snapshot', 64);
            $table->text('payload');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_changes');
    }
};
