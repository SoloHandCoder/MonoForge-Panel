<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            // The shared Minecraft entrance: one public entrance (a playit tunnel, or the game
            // gateway) to a Gate Lite proxy on the node (scripts/monoforge/mc-router.sh) that
            // routes players by the host name they joined with, <name>.<domain>.
            $table->boolean('mc_entrance_enabled')->default(false)->after('gateway_ports');
            $table->string('mc_entrance_domain', 253)->nullable()->after('mc_entrance_enabled');
            $table->string('mc_entrance_mode', 16)->nullable()->after('mc_entrance_domain');
            $table->unsignedInteger('mc_entrance_port')->default(25565)->after('mc_entrance_mode');
            $table->unsignedInteger('mc_entrance_proxy_port')->default(25500)->after('mc_entrance_port');
            $table->boolean('mc_entrance_proxy_protocol')->default(false)->after('mc_entrance_proxy_port');
            $table->boolean('mc_entrance_srv')->default(false)->after('mc_entrance_proxy_protocol');
            // The node's one playit tunnel to the proxy, in playit mode.
            $table->string('mc_entrance_tunnel_id', 64)->nullable()->after('mc_entrance_srv');
            $table->string('mc_entrance_address')->nullable()->after('mc_entrance_tunnel_id');
            $table->string('mc_entrance_error', 1000)->nullable()->after('mc_entrance_address');
            $table->timestamp('mc_entrance_synced_at')->nullable()->after('mc_entrance_error');
        });

        Schema::table('servers', function (Blueprint $table): void {
            // <name> in <name>.<domain>; unique across the panel so a server keeps it on any node.
            $table->string('mc_hostname', 40)->nullable()->unique()->after('description');
            // When paper-global.yml was set to take PROXY protocol, and when the server was seen
            // running with it (only then does the proxy send it).
            $table->timestamp('mc_proxy_protocol_written_at')->nullable()->after('mc_hostname');
            $table->timestamp('mc_proxy_protocol_at')->nullable()->after('mc_proxy_protocol_written_at');
        });
    }

    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table): void {
            $table->dropUnique(['mc_hostname']);
            $table->dropColumn(['mc_hostname', 'mc_proxy_protocol_written_at', 'mc_proxy_protocol_at']);
        });

        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn([
                'mc_entrance_enabled', 'mc_entrance_domain', 'mc_entrance_mode', 'mc_entrance_port',
                'mc_entrance_proxy_port', 'mc_entrance_proxy_protocol', 'mc_entrance_srv',
                'mc_entrance_tunnel_id', 'mc_entrance_address', 'mc_entrance_error', 'mc_entrance_synced_at',
            ]);
        });
    }
};
