<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role', 20)->default('employee');
            // Super Admin is source-less and sees all ERP sources.
            $t->string('source', 3)->nullable()->default(null);
            $t->string('school_id', 64)->default('PLP');
            $t->string('campus', 64)->default('Main');
            $t->boolean('active')->default(true);
        });
        Schema::create('folders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->constrained('users');
            $t->uuid('parent_id')->nullable();
            $t->foreign('parent_id')->references('id')->on('folders');
            $t->string('name', 150);
            $t->string('source', 3);
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignId('owner_id')->nullable()->constrained('users');
            $t->uuid('folder_id')->nullable();
            $t->foreign('folder_id')->references('id')->on('folders');
            $t->string('source', 3);
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('title', 200);
            $t->string('subject', 200)->nullable();
            $t->string('reference', 100)->nullable();
            $t->string('category', 100)->nullable();
            $t->string('classification', 20)->default('Confidential');
            $t->unsignedInteger('revision')->default(1);
            $t->timestamp('trashed_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'campus', 'source']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->bigIncrements('cursor');
            $t->uuid('id')->unique();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->unsignedInteger('number');
            $t->string('filename');
            $t->string('mime', 150);
            $t->unsignedBigInteger('size');
            $t->char('checksum', 64);
            $t->string('storage_key');
            $t->string('scan_status', 20)->default('Pending');
            $t->timestamp('created_at');
            $t->unique(['document_id', 'number']);
        });
        Schema::create('shares', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->foreignId('recipient_id')->constrained('users');
            $t->uuid('version_id')->nullable();
            $t->foreign('version_id')->references('id')->on('document_versions');
            $t->string('permission', 12);
            $t->timestamp('expires_at')->nullable();
            $t->timestamp('revoked_at')->nullable();
            $t->timestamps();
            $t->unique(['document_id', 'recipient_id']);
        });
        Schema::create('comments', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->foreignId('user_id')->constrained('users');
            $t->string('body', 2000);
            $t->timestamp('created_at');
        });
        Schema::create('stars', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->foreignId('user_id')->constrained('users');
            $t->unique(['document_id', 'user_id']);
        });
        Schema::create('connectors', function (Blueprint $t) {
            $t->id();
            $t->string('name', 100);
            $t->string('source', 3);
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->char('token_hash', 64)->unique();
            $t->boolean('active')->default(true);
            $t->timestamp('last_seen_at')->nullable();
            $t->timestamps();
        });
        Schema::create('source_snapshots', function (Blueprint $t) {
            $t->id();
            $t->char('record_key', 64)->unique();
            $t->string('source', 3);
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('record_type', 100);
            $t->string('record_id', 100);
            $t->unsignedBigInteger('revision')->default(0);
            $t->boolean('deleted')->default(false);
            $t->json('payload')->nullable();
            $t->timestamps();
        });
        Schema::create('history_receipts', function (Blueprint $t) {
            $t->id();
            $t->char('scope_key', 64);
            $t->char('record_key', 64);
            $t->string('event_id', 100);
            $t->string('source', 3);
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('record_type', 100);
            $t->string('record_id', 100);
            $t->unsignedBigInteger('revision');
            $t->char('request_hash', 64);
            $t->string('operation', 10);
            $t->json('payload');
            $t->boolean('applied');
            $t->timestamp('created_at');
            $t->unique(['scope_key', 'event_id']);
            $t->unique(['record_key', 'revision']);
        });
        Schema::create('request_receipts', function (Blueprint $t) {
            $t->id();
            $t->char('scope_key', 64);
            $t->string('request_key', 100);
            $t->char('request_hash', 64);
            $t->json('response')->nullable();
            $t->timestamp('created_at');
            $t->unique(['scope_key', 'request_key']);
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->id();
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('source', 3)->nullable();
            $t->foreignId('actor_id')->nullable()->constrained('users');
            $t->unsignedBigInteger('connector_id')->nullable();
            $t->string('action', 100);
            $t->uuid('document_id')->nullable();
            $t->json('details')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->string('source', 3);
            $t->uuid('document_id')->nullable();
            $t->string('type', 100);
            $t->unsignedBigInteger('revision');
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['integration_events', 'audit_entries', 'request_receipts', 'history_receipts', 'source_snapshots', 'connectors', 'stars', 'comments', 'shares', 'document_versions', 'documents', 'folders'] as $table) {
            Schema::dropIfExists($table);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'source', 'school_id', 'campus', 'active']));
    }
};
