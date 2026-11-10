<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ii_issuer_market_prices', function (Blueprint $table): void {
            $table->char('batch_sha256', 64)->nullable()->after('sha256');
            $table->string('batch_original_filename', 255)->nullable()->after('batch_sha256');
            $table->index(['user_id', 'batch_sha256'], 'ii_price_batch_user_sha_index');
        });
    }

    public function down(): void
    {
        Schema::table('ii_issuer_market_prices', function (Blueprint $table): void {
            $table->dropIndex('ii_price_batch_user_sha_index');
            $table->dropColumn(['batch_sha256', 'batch_original_filename']);
        });
    }
};
