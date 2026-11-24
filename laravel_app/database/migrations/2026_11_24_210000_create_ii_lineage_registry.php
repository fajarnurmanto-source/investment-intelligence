<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ii_lineage_nodes', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('kind', 24); // SOURCE or TRANSFORMATION
            $table->string('reference_type', 72);
            $table->string('reference_id', 128);
            $table->string('operation', 72);
            $table->char('fingerprint', 64);
            $table->char('evidence_sha256', 64); // digest only, never payload
            $table->string('rights_profile', 96);
            $table->string('rights_basis', 64); // CLAIMED / RESTRICTED_BYOK / UNKNOWN
            $table->boolean('can_display')->default(false);
            $table->boolean('can_export')->default(false);
            $table->boolean('can_cloud_transfer')->default(false);
            $table->boolean('can_persist_payload')->default(false);
            $table->json('source_metadata'); // sanitized names/URLs, no keys/numeric data
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['user_id', 'fingerprint']);
            $table->index(['user_id', 'reference_type', 'reference_id']);
        });

        Schema::create('ii_lineage_edges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->uuid('parent_node_id');
            $table->uuid('child_node_id');
            $table->foreign('parent_node_id')->references('id')->on('ii_lineage_nodes')->restrictOnDelete();
            $table->foreign('child_node_id')->references('id')->on('ii_lineage_nodes')->restrictOnDelete();
            $table->unique(['parent_node_id', 'child_node_id']);
            $table->index(['user_id', 'child_node_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ii_lineage_edges');
        Schema::dropIfExists('ii_lineage_nodes');
    }
};
