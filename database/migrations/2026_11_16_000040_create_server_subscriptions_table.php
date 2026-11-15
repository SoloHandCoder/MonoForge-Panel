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
            $table->unsignedInteger('plan_id')->nullable();
            $table->unsignedInteger('period_id')->nullable();
            $table->unsignedInteger('period_days');
            // Tokens per period, locked at purchase (multiplier and add-ons included).
            $table->unsignedInteger('price_tokens');
            $table->json('addons')->nullable();
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active');
            $table->dateTime('next_renewal_at');
            $table->dateTime('suspended_at')->nullable();
            // When billing's suspension reached the server. A server an admin unsuspends by hand
            // afterwards is not suspended again by the scheduler's Wings retry.
            $table->dateTime('server_suspended_at')->nullable();
            $table->dateTime('delete_at')->nullable();
            $table->boolean('auto_renew')->default(true);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('plan_id')->references('id')->on('shop_plans')->nullOnDelete();
            $table->foreign('period_id')->references('id')->on('shop_periods')->nullOnDelete();
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
};
