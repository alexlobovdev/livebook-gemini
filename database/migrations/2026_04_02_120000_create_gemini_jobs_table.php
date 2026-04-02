<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gemini_jobs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('source_system', 64)->default('crm');
            $table->string('source_entity_type', 64)->nullable();
            $table->unsignedBigInteger('source_entity_id')->nullable();
            $table->string('idempotency_key', 255)->unique();
            $table->string('status', 32)->default('queued')->index();
            $table->unsignedSmallInteger('attempt')->default(0);

            $table->longText('prompt');
            $table->string('model', 255)->nullable();
            $table->string('aspect_ratio', 16)->nullable();
            $table->string('image_size', 8)->nullable();
            $table->json('input_images')->nullable();

            $table->string('result_mime_type', 128)->nullable();
            $table->longText('result_base64_data')->nullable();
            $table->text('result_text')->nullable();
            $table->unsignedInteger('result_width')->nullable();
            $table->unsignedInteger('result_height')->nullable();
            $table->unsignedBigInteger('result_size_bytes')->nullable();
            $table->string('response_id', 255)->nullable();
            $table->string('model_version', 255)->nullable();
            $table->text('error_message')->nullable();

            $table->json('meta')->nullable();
            $table->timestamp('queued_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['source_entity_type', 'source_entity_id'], 'gemini_jobs_source_entity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gemini_jobs');
    }
};
