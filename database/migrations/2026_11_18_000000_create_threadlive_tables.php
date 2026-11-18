<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Published into the application by threadlive:install, never run from
 * vendor/: from then on the tables are the application's.
 *
 * States are strings, not MySQL ENUMs: a new state is a code change, not a
 * migration that rewrites the table. The host's user id is a string with no
 * foreign key — hosts use ints, UUIDs, or another database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('threadlive_conversations', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('status', 16)->default('waiting');
            $table->string('user_ref')->nullable()->index();
            $table->char('visitor_token_hash', 64)->nullable()->unique();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('locale', 16)->nullable();
            $table->json('context')->nullable();

            $table->unsignedBigInteger('telegram_thread_id')->nullable()->unique();
            $table->string('topic_state', 16)->default('none');
            // The name last set, so a rename happens only when it changes.
            $table->string('topic_name', 128)->nullable();
            $table->timestamp('topic_closed_at')->nullable();
            $table->timestamp('topic_deleted_at')->nullable();
            $table->bigInteger('assignee_tg_id')->nullable();
            $table->string('assignee_name')->nullable();
            $table->timestamp('assigned_at')->nullable();

            $table->timestamp('last_visitor_message_at')->nullable();
            $table->timestamp('last_agent_message_at')->nullable();
            $table->timestamp('last_activity_at')->nullable()->index();
            $table->unsignedBigInteger('visitor_read_message_id')->nullable();
            $table->timestamp('waiting_alert_sent_at')->nullable();
            $table->timestamp('unread_email_sent_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('closed_by', 16)->nullable();
            $table->timestamps();

            $table->index(['status', 'last_activity_at']);
        });

        Schema::create('threadlive_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('conversation_id')->constrained('threadlive_conversations')->cascadeOnDelete();
            $table->string('direction', 16);
            $table->text('body');
            $table->json('attachments')->nullable();
            $table->uuid('client_msg_id')->nullable();
            $table->bigInteger('telegram_message_id')->nullable();
            $table->bigInteger('telegram_from_id')->nullable();
            $table->string('agent_name')->nullable();
            $table->string('delivery', 16)->nullable();
            $table->timestamp('edited_at')->nullable();
            $table->timestamps();

            // A retried send must not store the message twice.
            $table->unique(['conversation_id', 'client_msg_id']);
            $table->index(['conversation_id', 'direction', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('threadlive_messages');
        Schema::dropIfExists('threadlive_conversations');
    }
};
