<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Pterodactyl\Services\Games\GameDetector;
use Pterodactyl\Services\Games\GameEggBackfiller;

return new class extends Migration
{
    public function up(): void
    {
        // Admin > Games: which game each egg runs. An egg belongs to at most one game, so the
        // link lives on the egg; deleting a game leaves its eggs to the built-in detection.
        Schema::create('games', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('slug', 32)->unique();
            $table->string('name', 64);
            $table->string('color', 7)->default('#6B7280');
            $table->string('icon', 48)->nullable();
            $table->boolean('built_in')->default(false);
            $table->boolean('auto_tunnels')->default(false);
            $table->json('extra_ports')->nullable();
            $table->timestamps();
        });

        Schema::table('eggs', function (Blueprint $table): void {
            $table->unsignedInteger('game_id')->nullable()->after('id');
            $table->foreign('game_id')->references('id')->on('games')->nullOnDelete();
        });

        $now = now();
        DB::table('games')->insert([
            [
                'slug' => GameDetector::MINECRAFT,
                'name' => 'Minecraft (Java)',
                'color' => '#5B8731',
                'icon' => 'pickaxe',
                'built_in' => true,
                'auto_tunnels' => true,
                // Simple Voice Chat listens on UDP 24454 by default.
                'extra_ports' => json_encode([['key' => 'voice', 'name' => 'Voice chat', 'protocol' => 'udp', 'port' => 24454, 'allocation' => null]], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
            [
                'slug' => GameDetector::TERRARIA,
                'name' => 'Terraria',
                'color' => '#3E8E41',
                'icon' => 'trees',
                'built_in' => true,
                'auto_tunnels' => true,
                'extra_ports' => json_encode([], JSON_THROW_ON_ERROR),
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        // Pre-fill the built-in games with the eggs the panel already detected as them.
        (new GameEggBackfiller(DB::connection()))->backfill();
    }

    public function down(): void
    {
        Schema::table('eggs', function (Blueprint $table): void {
            $table->dropForeign(['game_id']);
            $table->dropColumn('game_id');
        });

        Schema::dropIfExists('games');
    }
};
