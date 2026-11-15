<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How much the shop may sell on each node, next to the shop-wide limits; 0 is no limit.
        Schema::table('nodes', function (Blueprint $table): void {
            $table->unsignedInteger('shop_limit_memory_mb')->default(0)->after('shop_enabled');
            $table->unsignedInteger('shop_limit_disk_mb')->default(0)->after('shop_limit_memory_mb');
            $table->unsignedInteger('shop_limit_cpu_percent')->default(0)->after('shop_limit_disk_mb');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['shop_limit_memory_mb', 'shop_limit_disk_mb', 'shop_limit_cpu_percent']);
        });
    }
};
