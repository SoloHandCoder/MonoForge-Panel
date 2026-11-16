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
            // A free trial runs in the `trial` state until `next_renewal_at` (the end of the trial).
            $table->enum('status', ['active', 'suspended', 'cancelled', 'trial'])->default('active')->change();
            // Set while the subscription is a trial nobody has paid for yet, also after the trial
            // ended and the server was suspended; cleared when the player keeps the server.
            $table->boolean('is_trial')->default(false)->after('status');
        });

        // Every free trial ever started. The row outlives the server (and the account), so the
        // anti-abuse checks still see a trial whose server was deleted, and the admin list keeps
        // the conversion rate.
        Schema::create('shop_trials', function (Blueprint $table): void {
            $table->increments('id');
            // One trial per account (the unique index makes two concurrent starts impossible).
            $table->unsignedInteger('user_id')->nullable()->unique();
            $table->unsignedInteger('server_id')->nullable();
            $table->unsignedInteger('plan_id')->nullable();
            // HMAC of the /24 (IPv4) or /48 (IPv6) the trial was started from, and the one the
            // account signed up from; never the address itself.
            $table->string('subnet_hash', 64)->nullable();
            $table->string('signup_subnet_hash', 64)->nullable();
            // HMACs of the linked Discord and Google account ids at the start of the trial.
            $table->string('discord_hash', 64)->nullable();
            $table->string('google_hash', 64)->nullable();
            $table->unsignedSmallInteger('hours');
            $table->dateTime('ends_at');
            $table->dateTime('converted_at')->nullable();
            $table->dateTime('expired_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('plan_id')->references('id')->on('shop_plans')->nullOnDelete();
            $table->index(['subnet_hash', 'created_at']);
            $table->index(['signup_subnet_hash', 'created_at']);
            $table->index('discord_hash');
            $table->index('google_hash');
        });

        Schema::table('users', function (Blueprint $table): void {
            // HMAC of the /24 or /48 the account signed up from (free trial anti-abuse).
            $table->string('signup_subnet_hash', 64)->nullable()->after('has_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('signup_subnet_hash');
        });

        Schema::dropIfExists('shop_trials');

        // The narrowed enum has no `trial`: a trial nobody paid for becomes suspended (so the
        // billing engine removes it after the grace period) instead of an invalid value.
        DB::table('server_subscriptions')->where('status', 'trial')->update(['status' => 'suspended', 'delete_at' => now()->addDays(7)]);

        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('is_trial');
            $table->enum('status', ['active', 'suspended', 'cancelled'])->default('active')->change();
        });
    }
};
