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
            // The game gateway: a VPS that forwards these ports (TCP and UDP) through WireGuard
            // to the node (scripts/monoforge/gateway.sh). Players join `<host>:<port>` and the
            // node gets no playit.gg tunnels while it is on.
            $table->boolean('gateway_enabled')->default(false)->after('playit_switched_at');
            $table->string('gateway_host', 253)->nullable()->after('gateway_enabled');
            $table->string('gateway_ports', 255)->nullable()->after('gateway_host');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['gateway_enabled', 'gateway_host', 'gateway_ports']);
        });
    }
};
