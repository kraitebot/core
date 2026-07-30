<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('app_push_devices', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')
                ->nullable()
                ->constrained()
                ->nullOnDelete();
            $table->text('expo_push_token');
            $table->char('token_hash', 64)->unique();
            $table->string('platform', 16)->default('ios');
            $table->string('device_name', 100);
            $table->string('app_version', 32)->nullable();
            $table->timestamp('last_registered_at');
            $table->timestamp('disabled_at')->nullable();
            $table->timestamps();

            $table->index(
                ['user_id', 'disabled_at'],
                'app_push_devices_user_active_index',
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('app_push_devices');
    }
};
