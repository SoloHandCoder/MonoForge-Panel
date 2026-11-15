<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The AI crash doctor (phase 6 task D): servers that stopped without anyone asking, or
        // did not come up after a start. `console` holds the last console lines, masked.
        Schema::create('server_crashes', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->enum('kind', ['crashed', 'start_failed']);
            $table->enum('source', ['scheduler', 'console']);
            $table->timestamp('detected_at');
            $table->mediumText('console')->nullable();
            $table->enum('status', ['open', 'diagnosing', 'dismissed'])->default('open');
            $table->unsignedInteger('conversation_id')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('conversation_id')->references('id')->on('ai_conversations')->nullOnDelete();
            $table->index(['server_id', 'detected_at']);
            $table->index('detected_at');
        });

        // A crash doctor conversation has its own system prompt and pre-loaded logs.
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->enum('mode', ['chat', 'crash_doctor'])->default('chat')->after('scope');
        });
    }

    public function down(): void
    {
        Schema::table('ai_conversations', function (Blueprint $table): void {
            $table->dropColumn('mode');
        });

        Schema::dropIfExists('server_crashes');
    }
};
