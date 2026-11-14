<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_notices', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->enum('level', ['info', 'success', 'warning', 'danger'])->default('info');
            $table->string('title');
            $table->text('body');
            $table->string('action_label')->nullable();
            $table->string('action_url', 2048)->nullable();
            $table->string('kind')->nullable();
            $table->timestamp('read_at')->nullable();
            // Holds the kind only while the notice is unread, so a user has at most one unread notice of each kind.
            $table->string('unread_kind')->nullable()->storedAs('IF(`read_at` IS NULL, `kind`, NULL)');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->unique(['user_id', 'unread_kind']);
            $table->index(['user_id', 'read_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_notices');
    }
};
