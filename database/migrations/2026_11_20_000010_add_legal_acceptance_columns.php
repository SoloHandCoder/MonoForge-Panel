<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Which Terms of Service and Privacy Policy versions the user accepted, and when.
        // Null for accounts made before this was recorded (they count as version 1).
        Schema::table('users', function (Blueprint $table): void {
            $table->unsignedInteger('terms_accepted_version')->nullable()->after('token_balance');
            $table->timestamp('terms_accepted_at')->nullable()->after('terms_accepted_version');
            $table->unsignedInteger('privacy_accepted_version')->nullable()->after('terms_accepted_at');
        });

        // The Terms of Service version in force when the server was bought.
        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->unsignedInteger('terms_version')->nullable()->after('auto_renew');
        });
    }

    public function down(): void
    {
        Schema::table('server_subscriptions', function (Blueprint $table): void {
            $table->dropColumn('terms_version');
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_version', 'terms_accepted_at', 'privacy_accepted_version']);
        });
    }
};
