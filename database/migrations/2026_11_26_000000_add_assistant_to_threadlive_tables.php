<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The AI assistant (0.2.0). Published by threadlive:install next to the
 * first migration; conversations there already stay with the managers.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threadlive_conversations', function (Blueprint $table) {
            // Who answers: 'ai' (the assistant) or 'human' (the managers).
            $table->string('handler', 16)->default('human');
            $table->timestamp('handed_off_at')->nullable();
            $table->string('handoff_reason', 500)->nullable();
            $table->unsignedSmallInteger('assistant_replies')->default(0);
        });

        Schema::table('threadlive_messages', function (Blueprint $table) {
            $table->boolean('by_assistant')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('threadlive_messages', function (Blueprint $table) {
            $table->dropColumn('by_assistant');
        });

        Schema::table('threadlive_conversations', function (Blueprint $table) {
            $table->dropColumn(['handler', 'handed_off_at', 'handoff_reason', 'assistant_replies']);
        });
    }
};
