<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('token_packs', function (Blueprint $table): void {
            $table->increments('id');
            // The numeric Tebex package ID, which is what Tebex sends back in webhooks.
            $table->string('tebex_package_id', 191);
            $table->string('name');
            $table->unsignedInteger('tokens');
            $table->unsignedInteger('bonus_tokens')->default(0);
            $table->boolean('visible')->default(true);
            $table->integer('sort')->default(0);
            $table->timestamps();

            $table->index('tebex_package_id');
        });

        Schema::create('tebex_payments', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('transaction_id', 191)->unique();
            $table->unsignedInteger('user_id')->nullable();
            // completed, refunded, chargeback, disputed, declined or unmatched.
            $table->string('status', 32);
            // Tokens credited for the payment, and how many were taken back after a refund or a lost dispute.
            $table->integer('tokens')->default(0);
            $table->integer('tokens_reversed')->default(0);
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('currency', 8)->default('');
            // Every webhook received for the transaction, oldest first.
            $table->json('raw');
            $table->timestamp('processed_at')->nullable();
            // Set when the payment needs an administrator's attention: a refund, a dispute or no matching user.
            $table->timestamp('flagged_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index('flagged_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tebex_payments');
        Schema::dropIfExists('token_packs');
    }
};
