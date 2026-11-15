<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A request is kept per sender and typed username or email, whether or not a user matched it,
        // so the sender's list never shows which names exist. `recipient_id` is null when none did.
        // A declined request stays pending for the sender and is hidden from the recipient.
        Schema::create('friend_requests', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('sender_id');
            $table->unsignedInteger('recipient_id')->nullable();
            $table->string('identifier', 191);
            $table->timestamp('declined_at')->nullable();
            $table->timestamps();

            $table->foreign('sender_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('recipient_id')->references('id')->on('users')->nullOnDelete();
            $table->unique(['sender_id', 'identifier']);
            $table->index(['recipient_id', 'declined_at']);
        });

        // One row each way, so a user's friends are one query on `user_id`.
        Schema::create('friendships', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('friend_id');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('friend_id')->references('id')->on('users')->cascadeOnDelete();

            $table->unique(['user_id', 'friend_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
        Schema::dropIfExists('friend_requests');
    }
};
