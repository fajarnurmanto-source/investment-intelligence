<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void {
        Schema::table('users', function (Blueprint $table) {
            $table->string('ii_research_role', 16)->nullable();
        });
        Schema::table('ii_vs5_cases', function (Blueprint $table) {
            $table->foreignId('ii_created_by')->nullable()->constrained('users')->nullOnDelete();
        });
        Schema::table('ii_vs5_events', function (Blueprint $table) {
            $table->foreignId('ii_actor_id')->nullable()->constrained('users')->nullOnDelete();
        });
    }
    public function down(): void {
        Schema::table('ii_vs5_events', fn (Blueprint $table) => $table->dropConstrainedForeignId('ii_actor_id'));
        Schema::table('ii_vs5_cases', fn (Blueprint $table) => $table->dropConstrainedForeignId('ii_created_by'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('ii_research_role'));
    }
};
