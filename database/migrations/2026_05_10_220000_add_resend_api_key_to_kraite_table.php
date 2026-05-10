<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Kraite\Core\Models\Kraite;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->longText('resend_api_key')->nullable()->after('nowpayments_ipn_secret');
        });

        $engine = Kraite::first();

        if ($engine) {
            $engine->resend_api_key = config('services.resend.key');
            $engine->save();
        }
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn('resend_api_key');
        });
    }
};
