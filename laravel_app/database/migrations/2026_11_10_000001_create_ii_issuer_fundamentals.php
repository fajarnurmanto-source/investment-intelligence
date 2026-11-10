<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ii_issuer_sources', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('source_key', 64);
            $table->string('name', 160);
            $table->string('publisher', 160)->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->string('terms_url', 1000)->nullable();
            $table->string('license_status', 40);
            $table->string('usage_rights', 80);
            $table->boolean('redistribution_allowed')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'source_key']);
        });

        Schema::create('ii_issuer_documents', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('source_id')->constrained('ii_issuer_sources')->restrictOnDelete();
            $table->string('issuer_code', 16)->index();
            $table->string('issuer_name', 200);
            $table->string('report_period', 40);
            $table->date('report_date');
            $table->char('currency', 3);
            $table->unsignedBigInteger('unit_multiplier');
            $table->boolean('consolidated')->default(true);
            $table->string('format', 16);
            $table->string('original_filename', 255);
            $table->string('storage_path', 500);
            $table->char('sha256', 64);
            $table->uuid('restates_document_id')->nullable();
            $table->string('quality_status', 32);
            // Completeness is intentionally independent from authenticity, reconciliation and rights.
            $table->unsignedTinyInteger('quality_score')->default(0);
            $table->string('source_authenticity_status', 40)->default('UNVERIFIED');
            $table->string('numeric_reconciliation_status', 40)->default('UNRECONCILED');
            $table->string('licensing_assessment', 40)->default('UNVERIFIED');
            $table->string('provenance_confidence', 16)->default('LOW');
            $table->json('provenance');
            $table->timestampTz('imported_at');
            $table->timestamps();
            $table->unique(['user_id', 'sha256']);
            $table->foreign('restates_document_id')->references('id')->on('ii_issuer_documents')->nullOnDelete();
        });

        Schema::create('ii_issuer_periods', function (Blueprint $table): void {
            $table->id();
            $table->uuid('document_id');
            $table->unsignedSmallInteger('fiscal_year');
            $table->date('period_end');
            $table->boolean('restated')->default(false);
            foreach (['revenue', 'net_income_attributable', 'total_assets', 'total_equity', 'equity_attributable',
                'operating_cash_flow', 'shares_outstanding', 'total_debt', 'cash_and_equivalents',
                'ebitda', 'market_price'] as $metric) {
                $table->decimal($metric, 30, 6)->nullable();
            }
            $table->date('market_price_as_of')->nullable();
            $table->json('available_fields');
            $table->timestamps();
            $table->foreign('document_id')->references('id')->on('ii_issuer_documents')->cascadeOnDelete();
            $table->unique(['document_id', 'fiscal_year', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_issuer_periods');
        Schema::dropIfExists('ii_issuer_documents');
        Schema::dropIfExists('ii_issuer_sources');
    }
};
