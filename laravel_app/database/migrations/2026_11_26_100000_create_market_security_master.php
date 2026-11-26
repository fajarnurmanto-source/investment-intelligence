<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ii_market_security_master', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('mic', 4);
            $table->string('symbol', 16);
            $table->string('currency', 3);
            $table->string('session_timezone', 64);
            $table->string('status', 48);
            $table->timestamp('observed_at');
            $table->unique(['user_id', 'provider', 'mic', 'symbol'], 'ii_security_identity_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_market_security_master');
    }
};
