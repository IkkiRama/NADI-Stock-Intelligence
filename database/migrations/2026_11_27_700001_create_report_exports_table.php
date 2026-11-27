<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('portfolio_id')->constrained('portfolios')->cascadeOnDelete();
            $table->string('report_type', 40);
            $table->string('format', 16);
            $table->string('reporting_currency', 8);
            $table->string('cost_basis_method', 24);
            $table->string('range_code', 16);
            $table->json('parameters')->nullable();
            $table->unsignedInteger('row_count')->default(0);
            $table->boolean('is_demo')->default(false);
            $table->timestamp('generated_at');
            $table->timestamps();

            $table->index(['user_id', 'generated_at'], 're_user_gen_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
    }
};
