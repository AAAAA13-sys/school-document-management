<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $t) {
            $t->string('role')->default('reader');
            $t->string('school_id')->default('DEMO-SCHOOL');
            $t->string('campus')->default('Main campus');
            $t->boolean('active')->default(true);
        });
        Schema::create('documents', function (Blueprint $t) {
            $t->uuid('id')->primary();
            foreach (['title', 'subject', 'reference', 'source', 'category', 'classification', 'school_id', 'campus'] as $f) {
                $t->string($f);
            }
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->index(['school_id', 'campus', 'source']);
        });
        Schema::create('document_versions', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('document_id')->constrained('documents');
            $t->unsignedInteger('number');
            foreach (['filename', 'media_type', 'storage_key', 'checksum', 'scan', 'status', 'actor'] as $f) {
                $t->string($f);
            }
            $t->unsignedBigInteger('size');
            $t->date('expires_at')->nullable();
            $t->text('reason')->nullable();
            $t->string('reviewer')->nullable();
            $t->timestamp('reviewed_at')->nullable();
            $t->timestamp('created_at');
            $t->unique(['document_id', 'number']);
        });
        Schema::create('audit_entries', function (Blueprint $t) {
            $t->id();
            foreach (['school_id', 'campus', 'actor', 'action'] as $f) {
                $t->string($f);
            }
            $t->uuid('document_id')->nullable();
            $t->text('detail')->nullable();
            $t->timestamp('created_at');
        });
        Schema::create('integration_events', function (Blueprint $t) {
            $t->id();
            $t->uuid('event_id')->unique();
            foreach (['school_id', 'campus', 'source', 'type'] as $f) {
                $t->string($f);
            }
            $t->uuid('document_id');
            $t->uuid('version_id');
            $t->unsignedInteger('revision');
            $t->timestamp('created_at');
        });
        Schema::create('idempotency_requests', function (Blueprint $t) {
            $t->string('key')->primary();
            $t->string('hash');
            $t->text('response');
            $t->timestamp('created_at');
        });
        Schema::create('integration_accounts', function (Blueprint $t) {
            $t->id();
            foreach (['name', 'school_id', 'campus', 'source', 'token_hash'] as $f) {
                $t->string($f);
            }
            $t->boolean('active')->default(true);
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        foreach (['integration_accounts', 'idempotency_requests', 'integration_events', 'audit_entries', 'document_versions', 'documents'] as $name) {
            Schema::dropIfExists($name);
        }
        Schema::table('users', fn (Blueprint $t) => $t->dropColumn(['role', 'school_id', 'campus', 'active']));
    }
};
