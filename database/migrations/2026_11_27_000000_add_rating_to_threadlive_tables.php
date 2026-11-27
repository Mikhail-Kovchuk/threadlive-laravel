<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * The visitor's rating of a closed conversation (0.3.0). Published by
 * threadlive:install next to the earlier migrations.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('threadlive_conversations', function (Blueprint $table) {
            // 'good' or 'bad'; asked again after the next close.
            $table->string('rating', 8)->nullable();
            $table->string('rating_comment', 1000)->nullable();
            $table->timestamp('rated_at')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('threadlive_conversations', function (Blueprint $table) {
            $table->dropIndex(['rated_at']);
            $table->dropColumn(['rating', 'rating_comment', 'rated_at']);
        });
    }
};
