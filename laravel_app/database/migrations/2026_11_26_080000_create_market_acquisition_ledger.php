<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ii_market_request_days', function (Blueprint $table): void {
            $table->string('provider', 32);
            $table->date('utc_day');
            $table->unsignedInteger('attempts')->default(0);
            $table->primary(['provider', 'utc_day']);
        });
        Schema::create('ii_market_acquisition_receipts', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('ticker', 16);
            $table->string('provider', 32);
            $table->string('status', 80);
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->index(['user_id', 'ticker', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_market_acquisition_receipts');
        Schema::dropIfExists('ii_market_request_days');
    }
};
