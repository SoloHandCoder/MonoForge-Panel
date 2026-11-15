<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Token amounts are 64-bit everywhere, like the wallet balance and the ledger: a big pack times
 * its quantity, or a large price, must not overflow a 32-bit column and fail the save.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->bigInteger('tokens')->default(0)->change();
            $table->bigInteger('tokens_reversed')->default(0)->change();
        });

        Schema::table('token_packs', function (Blueprint $table): void {
            $table->unsignedBigInteger('tokens')->change();
            $table->unsignedBigInteger('bonus_tokens')->default(0)->change();
        });

        Schema::table('shop_plans', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_tokens')->change();
        });

        Schema::table('shop_addons', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_tokens')->change();
        });

        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->unsignedBigInteger('price_tokens')->change();
        });
    }

    public function down(): void
    {
        Schema::table('tebex_payments', function (Blueprint $table): void {
            $table->integer('tokens')->default(0)->change();
            $table->integer('tokens_reversed')->default(0)->change();
        });

        Schema::table('token_packs', function (Blueprint $table): void {
            $table->unsignedInteger('tokens')->change();
            $table->unsignedInteger('bonus_tokens')->default(0)->change();
        });

        Schema::table('shop_plans', function (Blueprint $table): void {
            $table->unsignedInteger('price_tokens')->change();
        });

        Schema::table('shop_addons', function (Blueprint $table): void {
            $table->unsignedInteger('price_tokens')->change();
        });

        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('price_tokens')->change();
        });
    }
};
