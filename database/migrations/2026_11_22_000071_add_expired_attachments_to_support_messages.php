<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // How many images of the message were removed when its ticket had been closed long enough.
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->unsignedTinyInteger('expired_attachments')->default(0)->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('support_messages', function (Blueprint $table): void {
            $table->dropColumn('expired_attachments');
        });
    }
};
