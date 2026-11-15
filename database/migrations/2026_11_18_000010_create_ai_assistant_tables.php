<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The AI providers the assistant talks to; the API key is encrypted by the model.
        Schema::create('ai_providers', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name', 191);
            $table->enum('preset', ['cline', 'clinepass', 'ollama', 'openrouter', 'deepseek', 'openai', 'custom']);
            $table->string('base_url', 191);
            $table->text('api_key')->nullable();
            $table->string('model', 191);
            $table->json('extra_headers')->nullable();
            $table->unsignedInteger('context_window')->default(32000);
            $table->boolean('supports_tools')->default(true);
            $table->boolean('enabled')->default(true);
            $table->timestamps();
        });

        Schema::create('ai_conversations', function (Blueprint $table): void {
            $table->increments('id');
            $table->uuid('uuid')->unique();
            $table->unsignedInteger('user_id');
            $table->enum('scope', ['server', 'admin']);
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedInteger('provider_id')->nullable();
            $table->string('title', 191);
            $table->text('summary')->nullable();
            $table->unsignedInteger('summarized_until_message_id')->nullable();
            $table->enum('status', ['idle', 'running', 'awaiting_approval', 'error'])->default('idle');
            $table->text('error')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('provider_id')->references('id')->on('ai_providers')->nullOnDelete();
            $table->index(['user_id', 'scope', 'server_id']);
        });

        Schema::create('ai_messages', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('conversation_id');
            $table->enum('role', ['user', 'assistant', 'tool']);
            $table->longText('content')->nullable();
            $table->json('tool_calls')->nullable();
            $table->string('tool_call_id', 191)->nullable();
            $table->unsignedInteger('prompt_tokens')->default(0);
            $table->unsignedInteger('completion_tokens')->default(0);
            $table->timestamp('created_at')->nullable();

            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
        });

        Schema::create('ai_tool_calls', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('conversation_id');
            $table->unsignedInteger('message_id');
            $table->string('call_id', 191);
            $table->string('name', 191);
            // What the UI shows for the call (the tool's describeCall) and the tool's small result summary.
            $table->string('label', 191)->nullable();
            $table->json('arguments');
            $table->boolean('needs_confirmation')->default(false);
            $table->enum('status', ['pending', 'approved', 'denied', 'running', 'done', 'failed'])->default('pending');
            $table->longText('result')->nullable();
            $table->json('display')->nullable();
            $table->unsignedInteger('decided_by')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();

            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->cascadeOnDelete();
            $table->foreign('message_id')->references('id')->on('ai_messages')->cascadeOnDelete();
            $table->foreign('decided_by')->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('ai_usage', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('conversation_id')->nullable();
            $table->enum('scope', ['server', 'admin']);
            $table->char('period', 7);
            $table->unsignedInteger('ai_tokens');
            $table->unsignedInteger('free_tokens');
            $table->unsignedBigInteger('panel_tokens_charged');
            $table->timestamp('created_at')->nullable();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->nullOnDelete();
            $table->index(['user_id', 'period']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage');
        Schema::dropIfExists('ai_tool_calls');
        Schema::dropIfExists('ai_messages');
        Schema::dropIfExists('ai_conversations');
        Schema::dropIfExists('ai_providers');
    }
};
