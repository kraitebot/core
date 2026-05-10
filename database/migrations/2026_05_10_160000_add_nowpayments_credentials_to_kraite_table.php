<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->longText('nowpayments_api_key')->nullable()->after('taapi_secret');
            $table->longText('nowpayments_ipn_secret')->nullable()->after('nowpayments_api_key');
        });
    }

    public function down(): void
    {
        Schema::table('kraite', function (Blueprint $table): void {
            $table->dropColumn(['nowpayments_api_key', 'nowpayments_ipn_secret']);
        });
    }
};
