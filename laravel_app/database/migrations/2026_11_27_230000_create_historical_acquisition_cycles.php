<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ii_historical_cycles', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->primary();
            $table->string('manifest_hash', 64);
            $table->string('generation', 100);
            $table->boolean('enabled')->default(false);
            $table->unsignedInteger('cursor')->default(0);
            $table->unsignedInteger('accepted')->default(0);
            $table->unsignedInteger('rejected')->default(0);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('state')->default('READY');
            $table->string('last_code')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamps();
        });
        Schema::create('ii_historical_cycle_receipts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('manifest_hash', 64);
            $table->string('ticker', 4);
            $table->string('status');
            $table->string('code')->nullable();
            $table->string('source_sha256', 64)->nullable();
            $table->timestamp('updated_at');
            $table->unique(['user_id','manifest_hash','ticker'], 'ii_hist_cycle_receipt_unique');
        });
    }
    public function down(): void
    {
        Schema::dropIfExists('ii_historical_cycle_receipts');
        Schema::dropIfExists('ii_historical_cycles');
    }
};
