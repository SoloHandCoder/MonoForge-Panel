<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // A server's public page at /s/{slug}: one per server, shown only while published.
        Schema::create('server_pages', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('server_id')->unique();
            $table->string('slug', 32)->unique();
            $table->boolean('published')->default(false);
            $table->boolean('noindex')->default(false);
            $table->text('description')->nullable();
            $table->char('banner_color', 7)->nullable();
            $table->string('banner_image', 64)->nullable();
            $table->unsignedInteger('votes')->default(0);
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
        });

        // One row per counted vote, kept for a day so a visitor votes once per 24 hours. Both keys
        // are keyed hashes (HMAC with the app key, per page): no address or cookie is stored.
        Schema::create('server_page_votes', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('server_page_id')->constrained('server_pages')->cascadeOnDelete();
            $table->char('address_hash', 64);
            $table->char('cookie_hash', 64);
            $table->timestamp('voted_at');

            $table->index(['server_page_id', 'address_hash', 'voted_at']);
            $table->index(['server_page_id', 'cookie_hash', 'voted_at']);
            $table->index('voted_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_page_votes');
        Schema::dropIfExists('server_pages');
    }
};
