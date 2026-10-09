<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('folders', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->uuid('parent_id')->nullable();
            foreach (['name', 'school_id', 'campus', 'source'] as $field) {
                $t->string($field);
            }
            $t->unsignedInteger('revision')->default(1);
            $t->timestamps();
            $t->foreign('parent_id')->references('id')->on('folders');
        });
        Schema::table('documents', function (Blueprint $t) {
            $t->foreignUuid('folder_id')->nullable()->constrained('folders');
            $t->timestamp('trashed_at')->nullable();
        });
        Schema::create('document_stars', function (Blueprint $t) {
            $t->foreignId('user_id')->constrained();
            $t->foreignUuid('document_id')->constrained();
            $t->primary(['user_id', 'document_id']);
        });
        Schema::create('document_shares', function (Blueprint $t) {
            $t->uuid('id')->primary();
            $t->foreignUuid('document_id')->constrained();
            $t->foreignUuid('version_id')->constrained('document_versions');
            $t->foreignId('created_by')->constrained('users');
            $t->foreignId('recipient_id')->constrained('users');
            $t->timestamp('expires_at');
            $t->timestamp('revoked_at')->nullable();
            $t->timestamp('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_shares');
        Schema::dropIfExists('document_stars');
        Schema::table('documents', fn (Blueprint $t) => $t->dropConstrainedForeignId('folder_id'));
        Schema::table('documents', fn (Blueprint $t) => $t->dropColumn('trashed_at'));
        Schema::dropIfExists('folders');
    }
};
