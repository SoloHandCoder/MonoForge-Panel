<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Pay-as-you-play: a plan may let its servers sleep when nobody plays, for a discount on
        // the time they slept.
        Schema::table('shop_plans', function (Blueprint $table): void {
            $table->boolean('sleep_allowed')->default(false)->after('node_ids');
            $table->unsignedTinyInteger('sleep_discount_percent')->default(0)->after('sleep_allowed');
            $table->unsignedSmallInteger('sleep_idle_minutes')->default(15)->after('sleep_discount_percent');
        });

        Schema::table('server_subscriptions', function (Blueprint $table): void {
            // The owner's switch; the plan must allow it as well.
            $table->boolean('auto_sleep')->default(false)->after('auto_renew');
            // Consecutive scheduler checks (one a minute) that found nobody online.
            $table->unsignedSmallInteger('idle_checks')->default(0)->after('auto_sleep');
            // Set while the server sleeps: auto-sleep stopped it and nobody started it since.
            $table->dateTime('sleep_started_at')->nullable()->after('idle_checks');
            // What the last renewal saved thanks to sleeping, for the billing card.
            $table->unsignedBigInteger('last_sleep_saved_tokens')->default(0)->after('sleep_started_at');
            $table->index(['status', 'auto_sleep']);
        });

        // Every sleep, so a renewal can sum the sleep inside the period it bills.
        Schema::create('server_sleep_sessions', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id');
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            // The plan's discount when the sleep started: a renewal uses it, so a later change to
            // the plan never alters the price of sleep that already happened.
            $table->unsignedTinyInteger('discount_percent')->default(0);
            // Minutes the per-minute check saw the server offline. Only these are discounted, so
            // a server that ignores the stop (or time nobody observed) earns nothing.
            $table->unsignedInteger('confirmed_offline_minutes')->default(0);
            $table->dateTime('last_confirmed_offline_at')->nullable();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->index(['server_id', 'started_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_sleep_sessions');

        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->dropIndex(['status', 'auto_sleep']);
            $table->dropColumn(['auto_sleep', 'idle_checks', 'sleep_started_at', 'last_sleep_saved_tokens']);
        });

        Schema::table('shop_plans', function (Blueprint $table): void {
            $table->dropColumn(['sleep_allowed', 'sleep_discount_percent', 'sleep_idle_minutes']);
        });
    }
};
