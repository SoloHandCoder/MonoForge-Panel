<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The playit.gg agent running on each node; the secret is encrypted by the model.
        Schema::table('nodes', function (Blueprint $table): void {
            $table->uuid('playit_agent_id')->nullable()->after('maintenance_mode');
            $table->text('playit_secret')->nullable()->after('playit_agent_id');
        });

        Schema::create('server_tunnels', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('server_id')->unique();
            $table->unsignedInteger('allocation_id')->nullable();
            $table->uuid('playit_tunnel_id')->nullable();
            $table->string('address')->nullable();
            $table->string('subdomain', 63)->nullable();
            $table->enum('status', ['pending', 'active', 'failed', 'deleted'])->default('pending');
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->foreign('server_id')->references('id')->on('servers')->cascadeOnDelete();
            $table->foreign('allocation_id')->references('id')->on('allocations')->nullOnDelete();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('server_tunnels');

        Schema::table('nodes', function (Blueprint $table): void {
            $table->dropColumn(['playit_agent_id', 'playit_secret']);
        });
    }
};
