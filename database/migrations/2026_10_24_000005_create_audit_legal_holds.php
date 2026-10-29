<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_legal_holds', function (Blueprint $t) {
            $t->id();
            $t->string('school_id', 64);
            $t->string('campus', 64);
            $t->text('reason');
            $t->foreignId('actor_id')->constrained('users');
            $t->timestamp('released_at')->nullable();
            $t->timestamps();
            $t->index(['school_id', 'campus', 'released_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_legal_holds');
    }
};
