<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_pages', function (Blueprint $table): void {
            // The server discovery page at /servers: on for every published page unless its owner
            // opts out; an administrator can hide a page from it without unpublishing the page.
            $table->boolean('discoverable')->default(true)->after('noindex');
            $table->boolean('hidden_from_discovery')->default(false)->after('discoverable');

            // The look: banner_color is the accent; the background is a solid colour, a gradient
            // between two colours, or the banner image. Every colour is #rrggbb.
            $table->string('tagline', 80)->nullable()->after('description');
            $table->string('background_style', 16)->default('solid')->after('banner_color');
            $table->char('background_from', 7)->nullable()->after('background_style');
            $table->char('background_to', 7)->nullable()->after('background_from');
            $table->string('button_style', 16)->default('rounded')->after('background_to');
            $table->boolean('show_players')->default(true)->after('button_style');
            $table->boolean('show_votes')->default(true)->after('show_players');
            $table->boolean('show_address')->default(true)->after('show_votes');
            $table->boolean('show_description')->default(true)->after('show_address');
            $table->string('discord_url', 255)->nullable()->after('show_description');
            $table->string('website_url', 255)->nullable()->after('discord_url');

            // The last status a page showed, written whenever its public payload is built (and by
            // the scheduled refresh), so the discovery page reads one table instead of asking
            // each game server.
            $table->string('last_status', 16)->nullable()->after('allow_wake');
            $table->unsignedInteger('last_players_online')->nullable()->after('last_status');
            $table->unsignedInteger('last_players_max')->nullable()->after('last_players_online');
            $table->timestamp('status_checked_at')->nullable()->after('last_players_max');

            $table->index(['published', 'discoverable', 'hidden_from_discovery'], 'server_pages_discovery_index');
        });
    }

    public function down(): void
    {
        Schema::table('server_pages', function (Blueprint $table): void {
            $table->dropIndex('server_pages_discovery_index');
            $table->dropColumn([
                'discoverable', 'hidden_from_discovery', 'tagline', 'background_style', 'background_from',
                'background_to', 'button_style', 'show_players', 'show_votes', 'show_address', 'show_description',
                'discord_url', 'website_url', 'last_status', 'last_players_online', 'last_players_max', 'status_checked_at',
            ]);
        });
    }
};
