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
        Schema::table('server_subscriptions', function (Blueprint $table): void {
            // True only when billing's suspension changed the server's state. A server that was
            // already suspended (by an administrator) is never unsuspended by a later payment.
            $table->boolean('suspended_by_billing')->default(false)->after('server_suspended_at');
        });

        // Suspensions recorded before this column existed were made by billing.
        DB::table('server_subscriptions')->whereNotNull('server_suspended_at')->update(['suspended_by_billing' => true]);
    }

    public function down(): void
    {
        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('suspended_by_billing');
        });
    }
};
