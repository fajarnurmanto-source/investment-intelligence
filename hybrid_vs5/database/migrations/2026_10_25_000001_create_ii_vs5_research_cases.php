<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('ii_vs5_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('ticker', 8);
            $table->string('title', 120);
            $table->string('company_name', 160);
            $table->string('source_id', 255);
            $table->string('review_status', 32)->default('OPEN');
            $table->timestamps();
        });
        Schema::create('ii_vs5_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('case_id');
            $table->string('kind', 32);
            $table->string('review_status', 32);
            $table->text('finding');
            $table->timestamp('created_at');
            $table->foreign('case_id')->references('id')->on('ii_vs5_cases')->onDelete('cascade');
        });
    }
    public function down(): void {
        Schema::dropIfExists('ii_vs5_events');
        Schema::dropIfExists('ii_vs5_cases');
    }
};
