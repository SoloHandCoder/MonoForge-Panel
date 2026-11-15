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
            // Off by default: visitors may wake the server only when the owner allows it.
            $table->boolean('allow_wake')->default(false);
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
            // The keyed hash of the visitor's /24 (IPv4) or /48 (IPv6): a page takes only a few
            // votes a day from one network.
            $table->char('network_hash', 64);
            $table->timestamp('voted_at');

            $table->index(['server_page_id', 'address_hash', 'voted_at']);
            $table->index(['server_page_id', 'network_hash', 'voted_at']);
            $table->index(['server_page_id', 'cookie_hash', 'voted_at']);
            $table->index('voted_at');
        });

        // Slugs given up by a rename: held for 30 days for the same server only.
        Schema::create('server_page_slug_holds', function (Blueprint $table): void {
            $table->id();
            $table->string('slug', 32)->unique();
            $table->unsignedInteger('server_id');
            $table->dateTime('expires_at');

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_page_slug_holds');
        Schema::dropIfExists('server_page_votes');
        Schema::dropIfExists('server_pages');
    }
};
