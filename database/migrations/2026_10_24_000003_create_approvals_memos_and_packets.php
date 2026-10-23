<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shares', fn (Blueprint $t) => $t->boolean('memo_generated')->default(false));
        Schema::create('sensitive_changes', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('requester_id')->constrained('users');
            $t->foreignId('reviewer_id')->nullable()->constrained('users');
            $t->foreignUuid('document_id')->constrained('documents');
            $t->string('action', 40);
            $t->string('requester_role', 30);
            $t->unsignedInteger('revision');
            $t->json('payload');
            $t->string('status', 20)->default('pending');
            $t->text('reason')->nullable();
            $t->timestamp('decided_at')->nullable();
            $t->timestamps();
        });
        Schema::create('memo_publications', function (Blueprint $t) {
            $t->foreignUuid('document_id')->primary()->constrained('documents');
            $t->uuid('version_id');
            $t->string('type', 100);
            $t->string('reference', 100);
            $t->string('issuer', 200);
            $t->json('audience');
            $t->timestamp('published_at');
        });
        Schema::create('applicant_packets', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('applicant_id', 100);
            $t->unsignedBigInteger('revision');
            $t->boolean('released');
            $t->json('versions');
            $t->timestamps();
            $t->unique(['school_id', 'campus', 'applicant_id']);
        });
        Schema::create('packet_receipts', function (Blueprint $t) {
            $t->id();
            $t->char('scope_key', 64);
            $t->string('event_id', 100);
            $t->string('applicant_id', 100);
            $t->unsignedBigInteger('revision');
            $t->char('request_hash', 64);
            $t->json('payload');
            $t->json('response');
            $t->timestamp('created_at');
            $t->unique(['scope_key', 'event_id']);
            $t->unique(['scope_key', 'applicant_id', 'revision']);
        });
    }

    public function down(): void
    {
        foreach (['packet_receipts', 'applicant_packets', 'memo_publications', 'sensitive_changes'] as $table) Schema::dropIfExists($table);
        Schema::table('shares', fn (Blueprint $t) => $t->dropColumn('memo_generated'));
    }
};
