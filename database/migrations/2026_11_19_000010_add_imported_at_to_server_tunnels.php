<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Set on tunnels made by hand on playit.gg and linked to a server by "Import existing
        // tunnels". The panel never deletes or changes those on playit unless told to.
        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->timestamp('imported_at')->nullable()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->dropColumn('imported_at');
        });
    }
};
