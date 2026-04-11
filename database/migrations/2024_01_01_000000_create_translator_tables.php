<?php

/**
 * Base migration for syriable/laravel-translator.
 *
 * This file creates the complete schema for v1.x. Future releases add
 * columns and indexes through separate additive migrations following the
 * naming convention:
 *
 *   database/migrations/add_{feature}_to_translator_tables.php.stub
 *
 * This approach guarantees:
 *  1. Existing installations are never forced to drop and recreate tables.
 *  2. Each new migration is independently reversible.
 *  3. The service provider registers all migrations in version order via
 *     `hasMigrations([...])` in the package configuration block.
 *
 * SCHEMA VERSIONING RULES:
 *  - Additive changes (new columns, new indexes): new migration file.
 *  - Non-breaking column renames: new migration with BOTH old and new column.
 *  - Table drops: always in a SEPARATE migration with a deprecation window.
 *  - Never modify this base migration after the package's public release.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $prefix = config('translator.table_prefix', 'ltu_');

        Schema::create($prefix.'languages', function (Blueprint $table): void {
            $table->id();
            $table->string('code', 20)->unique();
            $table->string('name');
            $table->string('native_name');
            $table->boolean('rtl')->default(false);
            $table->boolean('active')->default(false);
            $table->boolean('is_source')->default(false);
            $table->timestamps();
        });

        Schema::create($prefix.'groups', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('namespace')->nullable()->index();
            $table->string('file_format', 10)->default('php');
            $table->string('file_path')->nullable();
            $table->timestamps();
            $table->unique(['name', 'namespace']);
        });

        Schema::create($prefix.'translation_keys', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('group_id')->constrained($prefix.'groups')->cascadeOnDelete();
            $table->string('key');
            $table->json('parameters')->nullable();
            $table->boolean('is_html')->default(false);
            $table->boolean('is_plural')->default(false);
            $table->timestamps();
            $table->unique(['group_id', 'key']);
        });

        Schema::create($prefix.'translations', function (Blueprint $table) use ($prefix): void {
            $table->id();
            $table->foreignId('translation_key_id')->constrained($prefix.'translation_keys')->cascadeOnDelete();
            $table->foreignId('language_id')->constrained($prefix.'languages')->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->string('status', 20)->default('untranslated');
            $table->timestamps();
            $table->unique(['translation_key_id', 'language_id']);
            $table->index(['language_id', 'status']);
        });

        Schema::create($prefix.'import_logs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('locale_count')->default(0);
            $table->unsignedInteger('key_count')->default(0);
            $table->unsignedInteger('new_count')->default(0);
            $table->unsignedInteger('updated_count')->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('source', 20)->default('cli')->index();
            $table->string('triggered_by')->nullable();
            $table->boolean('fresh')->default(false);
            $table->timestamps();
        });

        Schema::create($prefix.'export_logs', function (Blueprint $table): void {
            $table->id();
            $table->integer('locale_count');
            $table->integer('file_count');
            $table->integer('key_count');
            $table->integer('duration_ms');
            $table->string('triggered_by')->nullable();
            $table->string('source');
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        Schema::create($prefix.'ai_translation_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 50);
            $table->string('model', 100);
            $table->string('source_language', 20);
            $table->string('target_language', 20);
            $table->string('group_name')->nullable();
            $table->unsignedInteger('key_count')->default(0);
            $table->unsignedInteger('translated_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('input_tokens_used')->default(0);
            $table->unsignedInteger('output_tokens_used')->default(0);
            $table->decimal('actual_cost_usd', 10, 6)->default(0);
            $table->decimal('estimated_cost_usd', 10, 6)->default(0);
            $table->unsignedInteger('duration_ms')->default(0);
            $table->string('source', 20)->default('cli')->index();
            $table->string('triggered_by')->nullable();
            $table->json('failed_keys')->nullable();
            $table->timestamps();
            $table->index(['provider', 'target_language']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        $prefix = config('translator.table_prefix', 'ltu_');

        Schema::dropIfExists($prefix.'translations');
        Schema::dropIfExists($prefix.'translation_keys');
        Schema::dropIfExists($prefix.'groups');
        Schema::dropIfExists($prefix.'languages');
        Schema::dropIfExists($prefix.'import_logs');
        Schema::dropIfExists($prefix.'export_logs');
        Schema::dropIfExists($prefix.'ai_translation_logs');
    }
};
