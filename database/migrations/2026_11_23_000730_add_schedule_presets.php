<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The Quick setup preset a schedule was made from, so the Schedules page can show which
        // presets are set up. The schedule and its tasks stay ordinary and editable.
        Schema::table('schedules', function (Blueprint $table): void {
            $table->string('preset', 32)->nullable()->after('name');
        });

        // A backup task can keep only its newest N backups: its oldest unlocked one is rotated
        // out when it makes a new one. The backups it made remember the task.
        Schema::table('tasks', function (Blueprint $table): void {
            $table->unsignedSmallInteger('keep_backups')->nullable()->after('payload');
        });

        Schema::table('backups', function (Blueprint $table): void {
            $table->unsignedInteger('task_id')->nullable()->after('server_id');
            $table->foreign('task_id')->references('id')->on('tasks')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('backups', function (Blueprint $table): void {
            $table->dropForeign(['task_id']);
            $table->dropColumn('task_id');
        });

        Schema::table('tasks', function (Blueprint $table): void {
            $table->dropColumn('keep_backups');
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropColumn('preset');
        });
    }
};
