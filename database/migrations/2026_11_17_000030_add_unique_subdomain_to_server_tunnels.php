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
        // Deleted tunnels give their name back, and names are stored lowercase.
        DB::table('server_tunnels')->where('status', 'deleted')->update(['subdomain' => null]);
        DB::table('server_tunnels')->whereNotNull('subdomain')->update(['subdomain' => DB::raw('LOWER(TRIM(subdomain))')]);

        // A name two purchases raced for stays with the older tunnel.
        $duplicates = DB::table('server_tunnels')->whereNotNull('subdomain')
            ->select('subdomain', DB::raw('MIN(id) as keep_id'))
            ->groupBy('subdomain')
            ->havingRaw('COUNT(*) > 1')
            ->get();
        foreach ($duplicates as $duplicate) {
            DB::table('server_tunnels')->where('subdomain', $duplicate->subdomain)->where('id', '!=', $duplicate->keep_id)->update(['subdomain' => null]);
        }

        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->unique('subdomain');
        });
    }

    public function down(): void
    {
        Schema::table('server_tunnels', function (Blueprint $table): void {
            $table->dropUnique(['subdomain']);
        });
    }
};
