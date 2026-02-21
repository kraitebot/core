<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Database\Seeders\AppLogSeeder;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_logs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('loggable_type');
            $table->unsignedBigInteger('loggable_id');
            $table->string('event');
            $table->string('severity')->default('info');
            $table->text('message');
            $table->json('metadata')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['loggable_type', 'loggable_id', 'created_at']);
        });

        (new AppLogSeeder)->run();
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_logs');
    }
};
