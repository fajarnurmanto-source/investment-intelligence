<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ii_issuer_corporate_actions', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer_code', 16);
            $table->date('effective_on');
            $table->string('action_type', 32);
            $table->unsignedInteger('new_shares');
            $table->unsignedInteger('old_shares');
            $table->string('source_name', 160);
            $table->string('source_url', 1000);
            $table->string('usage_rights', 80);
            $table->string('provenance_confidence', 16);
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['user_id', 'sha256']);
            $table->unique(['user_id', 'issuer_code', 'effective_on', 'action_type'], 'ii_ca_unique_event');
            $table->index(['user_id', 'issuer_code', 'effective_on'], 'ii_ca_user_ticker_date');
        });

        Schema::create('ii_issuer_share_basis_reviews', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer_code', 16);
            $table->date('period_end');
            $table->date('price_observed_on');
            $table->char('events_sha256', 64);
            $table->decimal('cumulative_share_factor', 24, 12);
            $table->string('source_name', 160);
            $table->string('source_url', 1000);
            $table->string('usage_rights', 80);
            $table->string('provenance_confidence', 16);
            $table->boolean('coverage_confirmed');
            $table->char('sha256', 64);
            $table->timestamps();
            $table->unique(['user_id', 'sha256']);
            $table->index(['user_id', 'issuer_code', 'period_end', 'price_observed_on'], 'ii_share_basis_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_issuer_share_basis_reviews');
        Schema::dropIfExists('ii_issuer_corporate_actions');
    }
};
