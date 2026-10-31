<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('socket_events', function (Blueprint $table) {
            $table->id();
            $table->string('user_id');
            $table->string('type', 40);
            $table->json('data');
            $table->timestamp('created_at')->useCurrent();
            $table->timestamp('published_at')->nullable()->index();
            $table->index(['user_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('socket_events');
    }
};
