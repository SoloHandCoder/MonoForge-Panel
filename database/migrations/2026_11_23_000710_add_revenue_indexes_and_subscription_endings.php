<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The revenue dashboard reads these by date: each range query walks an index instead of
        // scanning the ledger, which matters on the panel's small CPU.
        Schema::table('token_transactions', function (Blueprint $table): void {
            $table->index(['type', 'created_at']);
        });

        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->index('processed_at');
        });

        Schema::table('ai_usage', function (Blueprint $table): void {
            $table->index('created_at');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->index('created_at');
        });

        // A subscription that ends is gone with its server, so churn is recorded when it happens:
        // `cancelled` when billing stops, `unpaid` when an unpaid server is deleted, `deleted` when
        // the server of a running subscription is deleted. Kept without foreign keys to the
        // subscription and server, which no longer exist; plan and user are kept while they do.
        Schema::create('server_subscription_endings', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_subscription_id');
            $table->unsignedInteger('user_id')->nullable();
            $table->unsignedInteger('plan_id')->nullable();
            $table->enum('reason', ['cancelled', 'unpaid', 'deleted']);
            $table->unsignedInteger('price_tokens');
            $table->unsignedInteger('period_days');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('shop_plans')->nullOnDelete();
            // One ending per subscription and reason, so a retried delete never counts twice.
            $table->unique(['server_subscription_id', 'reason']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_subscription_endings');

        Schema::table('users', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('ai_usage', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
        });

        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->dropIndex(['processed_at']);
        });

        Schema::table('token_transactions', function (Blueprint $table): void {
            $table->dropIndex(['type', 'created_at']);
        });
    }
};
