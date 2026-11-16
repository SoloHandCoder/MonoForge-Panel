<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The agent the node used before it was switched to a self-managed one, kept so its old
        // tunnels can be listed and removed and so the switch can be undone.
        Schema::table('nodes', function (Blueprint $table): void {
            $table->uuid('playit_previous_agent_id')->nullable()->after('playit_mode');
            $table->text('playit_previous_secret')->nullable()->after('playit_previous_agent_id');
            $table->string('playit_previous_mode', 16)->nullable()->after('playit_previous_secret');
            $table->timestamp('playit_switched_at')->nullable()->after('playit_previous_mode');
        });

        // One playit.gg claim per node: the code the administrator accepts on playit.gg, the
        // self-managed agent secret it is exchanged for (encrypted) and the one-time token the
        // node's switch script uses to fetch that secret (only its SHA-256 is stored).
        Schema::create('playit_agent_claims', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('node_id')->unique();
            $table->string('code', 20);
            $table->string('status', 24);
            $table->uuid('agent_id')->nullable();
            $table->text('secret')->nullable();
            $table->char('token_hash', 64)->nullable()->index();
            $table->timestamp('token_expires_at')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->string('report', 24)->nullable();
            $table->timestamp('reported_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
        });

        // Tunnels on a node's previous agent after the switch, for "Remove old tunnels" and undo.
        Schema::create('playit_old_tunnels', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('node_id');
            $table->unsignedInteger('server_id')->nullable();
            $table->uuid('agent_id')->nullable();
            $table->uuid('playit_tunnel_id');
            $table->string('name')->nullable();
            $table->string('address')->nullable();
            $table->unsignedInteger('local_port')->nullable();
            $table->timestamp('removed_at')->nullable();
            $table->timestamps();

            $table->unique(['node_id', 'playit_tunnel_id']);
            $table->foreign('node_id')->references('id')->on('nodes')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('playit_old_tunnels');
        Schema::dropIfExists('playit_agent_claims');

        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['playit_previous_agent_id', 'playit_previous_secret', 'playit_previous_mode', 'playit_switched_at']);
        });
    }
};
