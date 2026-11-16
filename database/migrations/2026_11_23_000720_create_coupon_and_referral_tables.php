<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /** The ledger types before this migration. */
    private const array TYPES = ['purchase', 'refund', 'chargeback', 'server_charge', 'addon_charge', 'ai_usage', 'admin_adjust', 'correction'];

    public function up(): void
    {
        // Coupons: a code (stored upper case, so lookups are case-insensitive) of one type.
        // `percent` takes value% off the first period of a store purchase, `fixed` takes value
        // tokens off it, and `tokens` credits value tokens to the wallet on redeem.
        Schema::create('coupons', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('code', 32)->unique();
            $table->enum('type', ['percent', 'fixed', 'tokens']);
            $table->unsignedBigInteger('value');
            $table->string('description', 191)->nullable();
            // Null is no limit; the count is changed only under the coupon's row lock.
            $table->unsignedInteger('max_redemptions')->nullable();
            $table->unsignedInteger('redemptions_count')->default(0);
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_until')->nullable();
            // Null (or empty) is every plan / category; with both set, either one matching is enough.
            $table->json('plan_ids')->nullable();
            $table->json('category_ids')->nullable();
            $table->boolean('new_customers_only')->default(false);
            $table->boolean('enabled')->default(true);
            $table->unsignedInteger('created_by')->nullable();
            $table->timestamps();

            $table->foreign('created_by')->references('id')->on('users')->nullOnDelete();
        });

        // The audit list of redemptions. One per coupon and user, enforced by the database.
        Schema::create('coupon_redemptions', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('coupon_id');
            $table->unsignedInteger('user_id')->nullable();
            // What the coupon was worth: tokens off the first period, or tokens credited.
            $table->unsignedBigInteger('tokens');
            // The store purchase reference (the ledger reference of its charge), or null for a wallet credit.
            $table->string('purchase_reference', 64)->nullable();
            $table->unsignedInteger('server_id')->nullable();
            // A keyed hash of the redeeming address's /24 (IPv4) or /48 (IPv6) network.
            $table->char('network_hash', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('coupon_id')->references('id')->on('coupons')->restrictOnDelete();
            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->unique(['coupon_id', 'user_id']);
            $table->unique('purchase_reference');
            $table->index(['coupon_id', 'network_hash']);
        });

        Schema::create('referral_codes', function (Blueprint $table): void {
            $table->unsignedInteger('user_id')->primary();
            $table->string('code', 16)->unique();
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // A referred sign-up: one per referred user. See ReferralService for the statuses.
        Schema::create('referrals', function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->unsignedInteger('referrer_id');
            $table->unsignedInteger('referred_id')->unique();
            $table->enum('status', ['registered', 'held', 'paid', 'reversed', 'rejected', 'capped'])->default('registered');
            $table->char('network_hash', 64)->nullable();
            $table->timestamp('flagged_at')->nullable();
            $table->string('flag_reason', 255)->nullable();
            $table->unsignedInteger('reviewed_by')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->unsignedInteger('tebex_payment_id')->nullable();
            $table->timestamp('qualified_at')->nullable();
            $table->timestamp('payout_after')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('reversed_at')->nullable();
            $table->unsignedBigInteger('reward_tokens')->nullable();
            $table->timestamps();

            $table->foreign('referrer_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('referred_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('reviewed_by')->references('id')->on('users')->nullOnDelete();
            $table->foreign('tebex_payment_id')->references('id')->on('tebex_payments')->nullOnDelete();
            $table->index(['referrer_id', 'status', 'paid_at']);
            $table->index(['status', 'payout_after']);
            $table->index(['referrer_id', 'network_hash']);
        });

        Schema::table('token_transactions', function (Blueprint $table): void {
            $table->enum('type', [...self::TYPES, 'coupon', 'referral'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('token_transactions', function (Blueprint $table): void {
            $table->enum('type', self::TYPES)->change();
        });

        Schema::dropIfExists('referrals');
        Schema::dropIfExists('referral_codes');
        Schema::dropIfExists('coupon_redemptions');
        Schema::dropIfExists('coupons');
    }
};
