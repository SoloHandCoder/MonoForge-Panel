<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tebex_checkouts', function (Blueprint $table): void {
            $table->increments('id');
            // The public reference the player returns from Tebex with; only its owner can read it.
            $table->char('uuid', 36)->unique();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('token_pack_id')->nullable();
            // The Tebex basket the checkout created, once Tebex returned it.
            $table->string('basket_ident', 191)->nullable()->index();
            // The payment of this checkout, linked when its first payment webhook arrives.
            $table->unsignedInteger('tebex_payment_id')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('token_pack_id')->references('id')->on('token_packs')->nullOnDelete();
            $table->foreign('tebex_payment_id')->references('id')->on('tebex_payments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tebex_checkouts');
    }
};
