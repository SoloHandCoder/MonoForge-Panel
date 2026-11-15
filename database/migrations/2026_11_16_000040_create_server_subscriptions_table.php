<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('server_subscriptions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('user_id');
            $this->catalogKey($table, 'plan_id', 'shop_plans');
            $this->catalogKey($table, 'period_id', 'shop_periods');
            $table->unsignedInteger('period_days');
            // Tokens per period, locked at purchase (multiplier and add-ons included).
            $table->unsignedInteger('price_tokens');
            $table->json('addons')->nullable();
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            $table->dateTime('next_renewal_at');
            $table->dateTime('suspended_at')->nullable();
            $table->dateTime('delete_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->index(['status', 'next_renewal_at']);
            $table->index(['status', 'delete_at']);
        });

        // Each billing warning (balance short before a renewal, suspension, deletion tomorrow) is
        // sent once per subscription and period: the scheduler inserts here first and only sends
        // when the insert was new, so a re-run never repeats an email.
        Schema::create('server_subscription_warnings', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_subscription_id');
            $table->string('kind', 32);
            $table->string('period_key', 64);
            $table->timestamp('created_at')->nullable();

            $table->foreign('server_subscription_id')->references('id')->on('server_subscriptions')->cascadeOnDelete();
            $table->unique(['server_subscription_id', 'kind', 'period_key'], 'server_subscription_warnings_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_subscription_warnings');
        Schema::dropIfExists('server_subscriptions');
    }

    /**
     * A nullable key into Task R's catalog that is set null when the row is deleted. The column
     * matches the type of the catalog's id, and the foreign key is only added once the catalog
     * table exists (Task R's migrations 2026_11_16_000030..34 run first once both are merged).
     */
    private function catalogKey(Blueprint $table, string $column, string $catalog): void
    {
        $hasCatalog = Schema::hasTable($catalog);
        $bigint = ! $hasCatalog || Schema::getColumnType($catalog, 'id') === 'bigint';

        ($bigint ? $table->unsignedBigInteger($column) : $table->unsignedInteger($column))->nullable();

        if ($hasCatalog) {
            $table->foreign($column)->references('id')->on($catalog)->nullOnDelete();
        }
    }
};
