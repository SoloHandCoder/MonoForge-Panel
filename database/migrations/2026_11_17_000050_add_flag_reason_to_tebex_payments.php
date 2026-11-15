<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tebex_payments', function (Blueprint $table): void {
            // Why the payment was not credited (it matched no user or pack, or paid less than the
            // package price), shown to administrators before they approve it.
            $table->string('flag_reason', 255)->nullable()->after('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->dropColumn('flag_reason');
        });
    }
};
