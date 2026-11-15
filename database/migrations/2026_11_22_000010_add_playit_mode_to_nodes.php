<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // "read_only" once playit refused to create a tunnel with the node's agent key: the panel
        // then stops creating tunnels there and only links the ones made in the playit dashboard.
        Schema::table('nodes', function (Blueprint $table): void {
            $table->string('playit_mode', 16)->nullable()->after('playit_secret');
        });
    }

    public function down(): void
    {
        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn('playit_mode');
        });
    }
};
