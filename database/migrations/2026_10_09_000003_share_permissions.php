<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('document_shares', fn (Blueprint $t) => $t->string('permission')->default('viewer'));
        Schema::create('document_comments', function (Blueprint $t) {
            $t->id();
            $t->foreignUuid('document_id')->constrained();
            $t->foreignId('user_id')->constrained();
            $t->text('body');
            $t->timestamp('created_at');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('document_comments');
        Schema::table('document_shares', fn (Blueprint $t) => $t->dropColumn('permission'));
    }
};
