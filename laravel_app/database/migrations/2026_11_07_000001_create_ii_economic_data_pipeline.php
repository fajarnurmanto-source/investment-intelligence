<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('ii_data_sources', function (Blueprint $table): void {
            $table->string('id', 32)->primary();
            $table->string('name', 160);
            $table->string('source_type', 40);
            $table->text('base_url');
            $table->text('terms_url');
            $table->string('rights_status', 80);
            $table->text('attribution_text');
            $table->json('provenance')->nullable();
            $table->timestamp('last_success_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ii_ingestion_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_id', 32);
            $table->string('series_id', 32);
            $table->string('status', 32);
            $table->text('source_url');
            $table->timestamp('started_at');
            $table->timestamp('completed_at')->nullable();
            $table->unsignedInteger('records_received')->default(0);
            $table->unsignedInteger('records_written')->default(0);
            $table->unsignedInteger('missing_records')->default(0);
            $table->string('response_sha256', 64)->nullable();
            $table->text('failure_reason')->nullable();
            $table->json('quality')->nullable();
            $table->timestamps();
            $table->foreign('source_id')->references('id')->on('ii_data_sources');
            $table->index(['source_id', 'series_id', 'started_at']);
        });

        Schema::create('ii_economic_observations', function (Blueprint $table): void {
            $table->id();
            $table->string('source_id', 32);
            $table->string('series_id', 32);
            $table->date('observed_on');
            $table->decimal('value', 24, 8);
            $table->string('units', 120);
            $table->string('frequency', 40);
            $table->string('quality_status', 40);
            $table->timestamp('retrieved_at');
            $table->uuid('ingestion_run_id');
            $table->text('source_url');
            $table->string('raw_checksum', 64);
            $table->timestamps();
            $table->unique(['source_id', 'series_id', 'observed_on'], 'ii_econ_observation_unique');
            $table->foreign('source_id')->references('id')->on('ii_data_sources');
            $table->foreign('ingestion_run_id')->references('id')->on('ii_ingestion_runs');
            $table->index(['series_id', 'observed_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_economic_observations');
        Schema::dropIfExists('ii_ingestion_runs');
        Schema::dropIfExists('ii_data_sources');
    }
};
