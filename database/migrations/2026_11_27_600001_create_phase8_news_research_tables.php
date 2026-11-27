<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('data_source_id')->constrained('data_sources')->restrictOnDelete();
            $table->string('external_id', 120)->nullable();
            $table->string('source_name', 100);
            $table->string('title', 240);
            $table->string('slug', 180)->unique();
            $table->text('summary');
            $table->longText('body')->nullable();
            $table->string('url', 500)->nullable();
            $table->string('category', 32);
            $table->string('country_code', 8)->nullable();
            $table->string('language', 8)->default('id');
            $table->string('impact_tag', 24)->default('neutral');
            $table->timestamp('published_at');
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index('published_at', 'na_published_idx');
            $table->index(['category', 'published_at'], 'na_cat_pub_idx');
            $table->index('country_code', 'na_country_idx');
        });

        Schema::create('news_article_instrument', function (Blueprint $table) {
            $table->foreignId('news_article_id')->constrained('news_articles')->cascadeOnDelete();
            $table->foreignId('instrument_id')->constrained('instruments')->cascadeOnDelete();

            $table->primary(['news_article_id', 'instrument_id'], 'nai_pk');
            $table->index('instrument_id', 'nai_inst_idx');
        });

        Schema::create('research_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('instrument_id')->nullable()->constrained('instruments')->nullOnDelete();
            $table->string('title', 180);
            $table->string('template_type', 32)->default('thesis');
            $table->string('conviction', 24)->default('neutral');
            $table->string('stance', 24)->default('researching');
            $table->decimal('target_price', 20, 6)->nullable();
            $table->string('target_currency', 8)->nullable();
            $table->string('horizon', 24)->nullable();
            $table->text('summary')->nullable();
            $table->longText('thesis_body');
            $table->text('catalysts')->nullable();
            $table->text('risks')->nullable();
            $table->text('invalidation_criteria')->nullable();
            $table->json('tags')->nullable();
            $table->boolean('is_pinned')->default(false);
            $table->date('reviewed_at')->nullable();
            $table->boolean('is_demo')->default(false);
            $table->timestamps();

            $table->index(['user_id', 'is_pinned', 'updated_at'], 'rn_user_pin_upd_idx');
            $table->index(['user_id', 'instrument_id'], 'rn_user_inst_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('research_notes');
        Schema::dropIfExists('news_article_instrument');
        Schema::dropIfExists('news_articles');
    }
};
