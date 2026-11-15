<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Discord and Google accounts linked to panel users. The columns match the old
     * m12labs table so its links can be imported unchanged.
     */
    public function up(): void
    {
        Schema::create('user_oauth_accounts', function (Blueprint $table): void {
            $table->increments('id');
            $table->unsignedInteger('user_id');
            $table->string('provider', 32);
            $table->string('provider_user_id');
            // A snapshot of the identity, shown on the account page.
            $table->string('provider_username')->nullable();
            $table->string('provider_email')->nullable();
            $table->string('provider_avatar')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'provider_user_id']);
            $table->unique(['user_id', 'provider']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        // Accounts created through Discord or Google start with a random password nobody
        // knows; they cannot drop their last linked account until they set one.
        Schema::table('users', function (Blueprint $table): void {
            $table->boolean('has_password')->default(true)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('has_password');
        });

        Schema::dropIfExists('user_oauth_accounts');
    }
};
