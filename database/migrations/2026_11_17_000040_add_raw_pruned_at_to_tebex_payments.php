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
            // When the stored webhook bodies (buyer name, email, address, IP) were cut down to
            // their id, type and date; the payment row itself is kept.
            $table->timestamp('raw_pruned_at')->nullable()->after('raw');
            $table->index('updated_at');
        });
    }

    public function down(): void
    {
        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->dropIndex(['updated_at']);
            $table->dropColumn('raw_pruned_at');
        });
    }
};
