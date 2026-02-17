<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Database\Seeders\KraiteSeeder;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('canonical')->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('max_accounts')->nullable()->comment('null = unlimited');
            $table->unsignedInteger('max_exchanges')->nullable()->comment('null = unlimited');
            $table->decimal('max_balance', 15, 2)->nullable()->comment('null = unlimited');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Call seeder to populate subscriptions
        $seeder = new KraiteSeeder();
        $seeder->seedSubscriptions();
    }

    public function down(): void
    {
        Schema::dropIfExists('subscriptions');
    }
};
