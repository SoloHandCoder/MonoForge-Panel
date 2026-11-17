<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assistant controls: the stop button, approval modes and the pause switch.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            // "ask" asks before every change; "allow_everything" allows them without asking.
            $table->enum('approval_mode', ['ask', 'allow_everything'])->default('ask')->after('mode');
            // "Allow until my next message": the user message the allowance belongs to.
            $table->unsignedInteger('allow_until_message_id')->nullable()->after('approval_mode');
        });

        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            // A call that needs an explicit click even in "Allow everything".
            $table->boolean('always_ask')->default(false)->after('needs_confirmation');
            // How a call that was not clicked was allowed.
            $table->enum('auto_approved', ['allow_everything', 'until_next_message'])->nullable()->after('decided_at');
        });

        Schema::table('ai_messages', function (Blueprint $table): void {
            // A note in the thread instead of a model answer, such as "stopped".
            $table->string('notice', 32)->nullable()->after('tool_call_id');
        });

        // Per user: the "Pause the assistant" switch.
        Schema::create('ai_user_settings', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->boolean('paused')->default(false);
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_user_settings');

        Schema::table('ai_messages', function (Blueprint $table): void {
            $table->dropColumn('notice');
        });

        Schema::table('ai_tool_calls', function (Blueprint $table): void {
            $table->dropColumn(['always_ask', 'auto_approved']);
        });

        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn(['approval_mode', 'allow_until_message_id']);
        });
    }
};
