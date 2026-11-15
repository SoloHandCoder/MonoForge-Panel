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
        // The store offers only nodes the owner opted in, so nothing new is sold anywhere until
        // they choose where.
        Schema::table('nodes', function (Blueprint $table): void {
            $table->boolean('shop_enabled')->default(false)->after('public');
        });

        $this->enableNodesWithSales();
    }

    /**
     * Nodes the shop already sells on stay open, so live sales keep working: any node with a
     * subscription, or with a server bought in the store (its external id starts "store-").
     */
    public function enableNodesWithSales(): void
    {
        DB::table('nodes')
            ->whereIn('id', DB::table('servers')
                ->select('servers.node_id')
                ->whereIn('servers.id', DB::table('server_subscriptions')->select('server_id'))
                ->orWhere('servers.external_id', 'like', 'store-%'))
            ->update(['shop_enabled' => true]);
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('shop_enabled');
        });
    }
};
