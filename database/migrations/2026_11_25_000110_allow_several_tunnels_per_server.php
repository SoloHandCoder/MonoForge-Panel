<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A server can now have its main "game" tunnel plus one per extra port its game defines
        // (such as Simple Voice Chat). Existing rows are the main tunnel.
        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->string('port_key', 32)->default('game')->after('server_id');
            $table->string('label', 64)->nullable()->after('port_key');
            $table->string('protocol', 8)->nullable()->after('label');
            // Why the scheduler leaves a failed tunnel alone (not_a_game, tunnel_limit), and until when.
            $table->string('hold_reason', 32)->nullable()->after('last_error');
            $table->timestamp('retry_after')->nullable()->after('hold_reason');
        });

        // The composite index starts with server_id, so the foreign key keeps an index while the
        // old unique one is dropped.
        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->unique(['server_id', 'port_key']);
        });

        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->dropUnique(['server_id']);
        });
    }

    public function down(): void
    {
        // Only the main tunnels fit the old one-per-server shape.
        DB::table('server_tunnels')->where('port_key', '!=', 'game')->delete();

        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->unique('server_id');
        });

        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->dropUnique(['server_id', 'port_key']);
            $table->dropColumn(['port_key', 'label', 'protocol', 'hold_reason', 'retry_after']);
        });
    }
};
