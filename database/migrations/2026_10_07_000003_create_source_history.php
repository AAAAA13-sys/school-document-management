<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('source_records', function (Blueprint $t) {
            $t->string('id', 64)->primary();
            foreach (['school_id', 'campus', 'source', 'record_type', 'record_id'] as $field) {
                $t->string($field);
            }
            $t->unsignedBigInteger('revision')->default(0);
            $t->boolean('deleted')->default(false);
            $t->longText('payload')->nullable();
            $t->timestamps();
        });
        Schema::create('source_history', function (Blueprint $t) {
            $t->id();
            $t->foreignId('integration_account_id')->constrained('integration_accounts');
            $t->string('record_key', 64);
            $t->foreign('record_key')->references('id')->on('source_records');
            $t->uuid('event_id');
            $t->unsignedBigInteger('revision');
            $t->string('operation');
            $t->string('request_hash', 64);
            $t->longText('payload');
            $t->timestamp('occurred_at');
            $t->timestamp('received_at');
            $t->boolean('applied');
            $t->unique(['integration_account_id', 'event_id']);
            $t->unique(['record_key', 'revision']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('source_history');
        Schema::dropIfExists('source_records');
    }
};
