<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ii_idx_evidence_batches',function(Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->date('session_date');
            $table->string('source_sha256',64);
            $table->string('review_sha256',64);
            $table->text('review_json');
            $table->timestamp('created_at');
            $table->unique(['user_id','session_date']);
        });
        Schema::create('ii_idx_evidence_rows',function(Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('ii_idx_evidence_batches')->cascadeOnDelete();
            $table->string('ticker',5);
            $table->unsignedInteger('row_number');
            $table->string('quality',32);
            $table->text('payload');
            $table->unique(['batch_id','ticker']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_idx_evidence_rows');
        Schema::dropIfExists('ii_idx_evidence_batches');
    }
};
