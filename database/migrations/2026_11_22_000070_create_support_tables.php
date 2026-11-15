<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The categories a player picks for a ticket; the admin edits them.
        Schema::create('support_categories', function (Blueprint $table): void {
            $table->id();
            $table->string('name', 64);
            $table->unsignedSmallInteger('sort')->default(0);
            $table->timestamps();
        });

        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->id();
            $table->unsignedInteger('user_id');
            $table->unsignedInteger('server_id')->nullable();
            $table->foreignId('category_id')->nullable()->constrained('support_categories')->nullOnDelete();
            $table->unsignedInteger('assigned_to')->nullable();
            $table->string('subject', 191);
            $table->string('status', 16)->default('open');
            // The last public message (not an internal note): its ID, when, and by whom.
            $table->unsignedBigInteger('last_message_id')->nullable();
            $table->timestamp('last_message_at')->nullable();
            $table->boolean('last_message_by_staff')->default(false);
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('server_id')->references('id')->on('servers')->nullOnDelete();
            $table->foreign('assigned_to')->references('id')->on('users')->nullOnDelete();
            $table->index(['user_id', 'last_message_at']);
            $table->index(['status', 'last_message_at']);
        });

        // Replies and staff-only internal notes.
        Schema::create('support_messages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedInteger('user_id')->nullable();
            $table->boolean('is_staff')->default(false);
            $table->boolean('is_internal')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
            $table->index(['ticket_id', 'id']);
        });

        // Images on a message, stored on the private disk under a random name.
        Schema::create('support_attachments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('message_id')->constrained('support_messages')->cascadeOnDelete();
            $table->string('path', 191);
            $table->string('name', 191);
            $table->string('mime', 32);
            $table->unsignedInteger('size');
            $table->unsignedSmallInteger('width');
            $table->unsignedSmallInteger('height');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('support_canned_replies', function (Blueprint $table): void {
            $table->id();
            $table->string('title', 100);
            $table->text('body');
            $table->timestamps();
        });

        // The last public message each person has seen on a ticket, for the unread markers and
        // the staff badge. Message IDs, unlike timestamps, cannot tie within one second.
        Schema::create('support_ticket_reads', function (Blueprint $table): void {
            $table->foreignId('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->unsignedInteger('user_id');
            $table->unsignedBigInteger('message_id');

            $table->primary(['ticket_id', 'user_id']);
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });

        $now = now();
        DB::table('support_categories')->insert(array_map(fn (string $name, int $sort): array => [
            'name' => $name,
            'sort' => $sort,
            'created_at' => $now,
            'updated_at' => $now,
        ], ['Billing', 'Server problem', 'Account', 'Other'], [1, 2, 3, 4]));
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_reads');
        Schema::dropIfExists('support_canned_replies');
        Schema::dropIfExists('support_attachments');
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::dropIfExists('support_categories');
    }
};
