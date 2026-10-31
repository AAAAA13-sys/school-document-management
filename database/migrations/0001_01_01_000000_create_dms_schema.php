<?php

use Database\Seeders\DocumentTypeSeeder;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // 1. Users
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password');
            $table->rememberToken();
            $table->string('role', 20)->default('staff');
            $table->string('source', 64)->nullable();
            $table->string('school_id', 64)->default('PLP');
            $table->string('campus', 64)->default('Main');
            $table->boolean('active')->default(true);
            $table->unsignedInteger('auth_version')->default(1);
            $table->timestamps();
        });

        // 2. Password reset tokens
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // 3. Sessions
        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });

        // 4. Cache
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });

        // 5. Jobs
        Schema::create('jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // 6. Workspaces
        Schema::create('workspaces', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->string('code', 64);
            $table->string('name', 150);
            $table->string('kind', 12)->default('erp');
            $table->string('status', 12)->default('active');
            $table->timestamps();
            $table->unique(['school_id', 'campus', 'code']);
        });

        Schema::create('workspace_memberships', function (Blueprint $table) {
            $table->id();
            $table->foreignId('workspace_id')->constrained('workspaces')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['workspace_id', 'user_id']);
        });

        // 7. Folders
        Schema::create('folders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('parent_id')->nullable();
            $table->foreign('parent_id')->references('id')->on('folders')->nullOnDelete();
            $table->string('name', 150);
            $table->string('source', 64);
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->unsignedInteger('revision')->default(1);
            $table->timestamps();
        });

        Schema::create('folder_shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('folder_id')->constrained('folders')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->string('permission', 16)->default('view');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->unique(['folder_id', 'recipient_id']);
        });

        // 8. Documents & Versions
        Schema::create('documents', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('owner_id')->nullable()->constrained('users')->nullOnDelete();
            $table->uuid('folder_id')->nullable();
            $table->foreign('folder_id')->references('id')->on('folders')->nullOnDelete();
            $table->string('source', 64)->index();
            $table->string('school_id', 64)->default('PLP');
            $table->string('campus', 64)->default('Main');
            $table->string('title', 200);
            $table->string('subject', 200)->nullable();
            $table->string('reference', 100)->nullable()->index();
            $table->string('category', 100)->nullable();
            $table->string('classification', 20)->default('Internal');
            $table->unsignedInteger('revision')->default(1);
            $table->timestamp('trashed_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'campus', 'source']);
        });

        Schema::create('document_versions', function (Blueprint $table) {
            $table->bigIncrements('cursor');
            $table->uuid('id')->unique();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('filename');
            $table->string('mime', 150);
            $table->unsignedBigInteger('size');
            $table->char('checksum', 64)->index();
            $table->string('storage_key');
            $table->string('scan_status', 20)->default('Pending');
            $table->timestamp('created_at');
            $table->timestamp('scan_attempted_at')->nullable()->index();
            $table->unique(['document_id', 'number']);
        });

        // 9. Document Shares, Stars, Comments
        Schema::create('shares', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('version_id')->nullable();
            $table->foreign('version_id')->references('id')->on('document_versions')->nullOnDelete();
            $table->string('permission', 16);
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->boolean('memo_generated')->default(false);
            $table->boolean('folder_inheritance')->default(false);
            $table->timestamps();
            $table->unique(['document_id', 'recipient_id']);
        });

        Schema::create('stars', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->unique(['document_id', 'user_id']);
        });

        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('body', 2000);
            $table->timestamp('created_at');
        });

        // 10. Audit Entries & Legal Holds
        Schema::create('audit_entries', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 64)->default('PLP');
            $table->string('campus', 64)->default('Main');
            $table->string('source', 64)->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->unsignedBigInteger('connector_id')->nullable();
            $table->string('action', 100);
            $table->uuid('document_id')->nullable();
            $table->json('details')->nullable();
            $table->timestamp('created_at');
            $table->index(['school_id', 'campus', 'action']);
        });

        Schema::create('audit_legal_holds', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->text('reason');
            $table->foreignId('actor_id')->constrained('users')->cascadeOnDelete();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'campus', 'released_at']);
        });

        // 11. Connectors, Snapshots, Receipts, Events
        Schema::create('connectors', function (Blueprint $table) {
            $table->id();
            $table->string('name', 100);
            $table->string('source', 64);
            $table->string('school_id', 64)->default('PLP');
            $table->string('campus', 64)->default('Main');
            $table->char('token_hash', 64)->unique();
            $table->boolean('active')->default(true);
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamps();
        });

        Schema::create('source_snapshots', function (Blueprint $table) {
            $table->id();
            $table->char('record_key', 64)->unique();
            $table->string('source', 64);
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->string('record_type', 100);
            $table->string('record_id', 100);
            $table->unsignedBigInteger('revision')->default(0);
            $table->boolean('deleted')->default(false);
            $table->json('payload')->nullable();
            $table->timestamps();
            $table->index(['source', 'school_id', 'campus', 'record_type', 'record_id'], 'source_snapshots_lookup_idx');
        });

        Schema::create('history_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('scope_key', 64);
            $table->char('record_key', 64);
            $table->string('event_id', 100);
            $table->string('source', 64);
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->string('record_type', 100);
            $table->string('record_id', 100);
            $table->unsignedBigInteger('revision');
            $table->char('request_hash', 64);
            $table->string('operation', 10);
            $table->json('payload');
            $table->boolean('applied');
            $table->timestamp('created_at');
            $table->unique(['scope_key', 'event_id']);
            $table->unique(['record_key', 'revision']);
        });

        Schema::create('request_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('scope_key', 64);
            $table->string('request_key', 100);
            $table->char('request_hash', 64);
            $table->json('response')->nullable();
            $table->timestamp('created_at');
            $table->unique(['scope_key', 'request_key']);
        });

        Schema::create('integration_events', function (Blueprint $table) {
            $table->id();
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->string('source', 64);
            $table->uuid('document_id')->nullable();
            $table->string('type', 100);
            $table->unsignedBigInteger('revision');
            $table->timestamp('created_at');
        });

        // 12. Invitations
        Schema::create('account_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
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
            $table->string('active_email')->nullable()->unique();
            $table->timestamp('sent_at')->nullable();
            $table->timestamps();
        });

        Schema::create('employee_invitations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->string('role', 20)->default('staff');
            $table->string('source', 3);
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->foreignId('invited_by')->constrained('users')->restrictOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('accepted_user_id')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('revoked_at')->nullable();
            $table->string('pending_email')->nullable()->unique();
            $table->string('delivery_status', 20)->default('not_sent');
            $table->timestamp('delivery_attempted_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->string('delivery_error_code', 40)->nullable();
            $table->timestamps();
            $table->index(['school_id', 'campus', 'source'], 'invitations_scope_index');
            $table->index(['email', 'expires_at']);
            $table->index('expires_at');
        });

        // 13. Sensitive Changes & Memos & Applicant Packets
        Schema::create('sensitive_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignUuid('document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('action', 40);
            $table->string('requester_role', 30);
            $table->unsignedInteger('revision');
            $table->json('payload');
            $table->string('status', 20)->default('pending');
            $table->text('reason')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('memo_publications', function (Blueprint $table) {
            $table->foreignUuid('document_id')->primary()->constrained('documents')->cascadeOnDelete();
            $table->uuid('version_id');
            $table->string('type', 100);
            $table->string('reference', 100);
            $table->string('issuer', 200);
            $table->json('audience');
            $table->timestamp('published_at');
        });

        Schema::create('applicant_packets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('school_id', 64);
            $table->string('campus', 64);
            $table->string('applicant_id', 100);
            $table->unsignedBigInteger('revision');
            $table->boolean('released');
            $table->json('versions');
            $table->timestamps();
            $table->unique(['school_id', 'campus', 'applicant_id']);
        });

        Schema::create('packet_receipts', function (Blueprint $table) {
            $table->id();
            $table->char('scope_key', 64);
            $table->string('event_id', 100);
            $table->string('applicant_id', 100);
            $table->unsignedBigInteger('revision');
            $table->char('request_hash', 64);
            $table->json('payload');
            $table->json('response');
            $table->timestamp('created_at');
            $table->unique(['scope_key', 'event_id']);
            $table->unique(['scope_key', 'applicant_id', 'revision']);
        });

        Schema::create('account_changes', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewer_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('snapshot', 64);
            $table->text('payload');
            $table->string('status')->default('pending');
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        // 14. Document Types & Requests
        Schema::create('document_types', function (Blueprint $table) {
            $table->id();
            $table->string('code', 64)->unique();
            $table->string('name', 150);
            $table->string('workspace', 64);
            $table->string('classification', 20)->default('Internal');
            $table->text('description')->nullable();
            $table->timestamps();
        });

        Schema::create('requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('title');
            $table->text('description')->nullable();
            $table->string('type')->default('document_access');
            $table->string('priority')->default('medium');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('reviewer_id')->nullable();
            $table->text('reason')->nullable();
            $table->timestamps();
        });

        // Case-sensitive receipt keys in MySQL
        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE history_receipts MODIFY event_id VARCHAR(100) COLLATE utf8mb4_bin NOT NULL');
            DB::statement('ALTER TABLE request_receipts MODIFY request_key VARCHAR(100) COLLATE utf8mb4_bin NOT NULL');
        }

        // Seed default ERP Workspaces
        $now = now();
        $workspaces = [
            'EMS' => 'Employee Management',
            'PMS' => 'Payroll Management',
            'ES' => 'Enrollment',
            'OAS' => 'Online Admission',
        ];

        foreach ($workspaces as $code => $name) {
            DB::table('workspaces')->insert([
                'school_id' => 'PLP',
                'campus' => 'Main',
                'code' => $code,
                'name' => $name,
                'kind' => 'erp',
                'status' => 'active',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        // Outside unit tests, seed the Super Administrator and Document Types so a fresh migrate is ready to use
        if (! app()->runningUnitTests()) {
            $password = (string) config('dms.demo_password', 'Password123!');
            DB::table('users')->insert([
                'name' => 'DMS Administrator',
                'email' => 'dms.admin@plpasig.edu.ph',
                'password' => Hash::make($password),
                'role' => 'super_admin',
                'source' => null,
                'school_id' => 'PLP',
                'campus' => 'Main',
                'active' => 1,
                'auth_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            if (class_exists(DocumentTypeSeeder::class)) {
                (new DocumentTypeSeeder)->run();
            }
        }
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();

        foreach ([
            'requests',
            'document_types',
            'account_changes',
            'packet_receipts',
            'applicant_packets',
            'memo_publications',
            'sensitive_changes',
            'employee_invitations',
            'account_invitations',
            'integration_events',
            'request_receipts',
            'history_receipts',
            'source_snapshots',
            'connectors',
            'audit_legal_holds',
            'audit_entries',
            'comments',
            'stars',
            'shares',
            'document_versions',
            'documents',
            'folder_shares',
            'folders',
            'workspace_memberships',
            'workspaces',
            'failed_jobs',
            'job_batches',
            'jobs',
            'cache_locks',
            'cache',
            'sessions',
            'password_reset_tokens',
            'users',
        ] as $table) {
            Schema::dropIfExists($table);
        }

        Schema::enableForeignKeyConstraints();
    }
};
