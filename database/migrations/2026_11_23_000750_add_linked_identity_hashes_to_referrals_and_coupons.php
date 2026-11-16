<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One referral reward and one free-tokens coupon per linked Discord or Google account: the
 * accounts' keyed hashes (TrialFingerprint::identity) are kept on the referral that earned a
 * reward and on the coupon redemption, and the unique indexes make a second one impossible.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('referrals', function (Blueprint $table): void {
            $table->char('discord_hash', 64)->nullable()->after('network_hash');
            $table->char('google_hash', 64)->nullable()->after('discord_hash');
            $table->unique('discord_hash');
            $table->unique('google_hash');
        });

        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->char('discord_hash', 64)->nullable()->after('network_hash');
            $table->char('google_hash', 64)->nullable()->after('discord_hash');
            $table->unique(['coupon_id', 'discord_hash']);
            $table->unique(['coupon_id', 'google_hash']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::table('coupon_redemptions', function (Blueprint $table): void {
            $table->dropIndex(['created_at']);
            $table->dropUnique(['coupon_id', 'discord_hash']);
            $table->dropUnique(['coupon_id', 'google_hash']);
            $table->dropColumn(['discord_hash', 'google_hash']);
        });

        Schema::table('referrals', function (Blueprint $table): void {
            $table->dropUnique(['discord_hash']);
            $table->dropUnique(['google_hash']);
            $table->dropColumn(['discord_hash', 'google_hash']);
        });
    }
};
