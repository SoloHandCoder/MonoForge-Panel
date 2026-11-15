<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Subuser permission presets. A null `user_id` is a global template an admin made; otherwise
        // the template belongs to that user's account. Templates only pre-fill the subuser form, so
        // editing one never changes a subuser.
        Schema::create('permission_templates', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id')->nullable();
            $table->string('name', 64);
            $table->json('permissions');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permission_templates');
    }
};
