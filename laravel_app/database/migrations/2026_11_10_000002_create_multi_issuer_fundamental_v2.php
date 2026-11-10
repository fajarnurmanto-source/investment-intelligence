<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ii_issuer_documents', function (Blueprint $table): void {
            $table->string('sector', 80)->default('Unclassified')->after('issuer_name');
            $table->string('fiscal_period_type', 20)->default('annual')->after('report_period');
            $table->string('conflict_status', 32)->default('NONE')->after('provenance_confidence');
            $table->json('conflict_details')->nullable()->after('conflict_status');
            $table->string('taxonomy_status', 32)->default('NOT_APPLICABLE')->after('conflict_details');
            $table->json('taxonomy_namespaces')->nullable()->after('taxonomy_status');
            $table->index(['user_id', 'sector', 'issuer_code']);
        });

        Schema::create('ii_issuer_import_batches', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('original_filename');
            $table->char('manifest_sha256', 64);
            $table->string('status', 32)->default('PENDING');
            $table->unsignedSmallInteger('total_items');
            $table->unsignedSmallInteger('succeeded_items')->default(0);
            $table->unsignedSmallInteger('failed_items')->default(0);
            $table->json('error_summary')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('ii_issuer_import_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('batch_id');
            $table->foreign('batch_id')->references('id')->on('ii_issuer_import_batches')->cascadeOnDelete();
            $table->uuid('document_id')->nullable();
            $table->foreign('document_id')->references('id')->on('ii_issuer_documents')->nullOnDelete();
            $table->string('original_filename');
            $table->string('staging_path', 500);
            $table->char('sha256', 64);
            $table->string('ticker', 16);
            $table->string('status', 32)->default('PENDING');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->json('metadata');
            $table->text('error_message')->nullable();
            $table->timestampTz('processed_at')->nullable();
            $table->timestamps();
            $table->unique(['batch_id', 'original_filename']);
        });

        Schema::create('ii_issuer_market_prices', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('issuer_code', 16);
            $table->date('observed_on');
            $table->char('currency', 3);
            $table->decimal('price', 30, 6);
            $table->string('unit', 24)->default('per_share');
            $table->string('adjustment_status', 32);
            $table->string('source_name', 160);
            $table->string('publisher', 160)->nullable();
            $table->string('source_url', 1000)->nullable();
            $table->string('terms_url', 1000)->nullable();
            $table->string('license_status', 40);
            $table->string('usage_rights', 80);
            $table->boolean('redistribution_allowed')->default(false);
            $table->string('source_authenticity_status', 40);
            $table->string('provenance_confidence', 16);
            $table->char('sha256', 64);
            $table->timestampTz('imported_at');
            $table->timestamps();
            $table->unique(['user_id', 'sha256']);
            $table->index(['user_id', 'issuer_code', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_issuer_market_prices');
        Schema::dropIfExists('ii_issuer_import_items');
        Schema::dropIfExists('ii_issuer_import_batches');
        Schema::table('ii_issuer_documents', function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'sector', 'issuer_code']);
            $table->dropColumn(['sector', 'fiscal_period_type', 'conflict_status', 'conflict_details', 'taxonomy_status', 'taxonomy_namespaces']);
        });
    }
};
