<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            // TAAPI pivotpoints response layout — R3/R2/R1 resistance,
            // P pivot midpoint, S1/S2/S3 support. All seven levels are
            // stored so the S/R gate can use R1/S1 for proximity math AND
            // R3/S3 for direction-aware breakout detection without a
            // second migration if we later need the intermediate levels.
            // Nullable because a symbol that hasn't concluded a direction
            // yet has no pivot data (pivotpoints is only persisted in
            // the finalization phase after direction is set).
            $table->decimal('pivot_r3', 20, 8)->nullable()->after('max_price');
            $table->decimal('pivot_r2', 20, 8)->nullable()->after('pivot_r3');
            $table->decimal('pivot_r1', 20, 8)->nullable()->after('pivot_r2');
            $table->decimal('pivot_p', 20, 8)->nullable()->after('pivot_r1');
            $table->decimal('pivot_s1', 20, 8)->nullable()->after('pivot_p');
            $table->decimal('pivot_s2', 20, 8)->nullable()->after('pivot_s1');
            $table->decimal('pivot_s3', 20, 8)->nullable()->after('pivot_s2');
            $table->timestamp('pivot_synced_at')->nullable()->after('pivot_s3');
        });
    }

    public function down(): void
    {
        Schema::table('exchange_symbols', function (Blueprint $table) {
            $table->dropColumn([
                'pivot_r3', 'pivot_r2', 'pivot_r1',
                'pivot_p',
                'pivot_s1', 'pivot_s2', 'pivot_s3',
                'pivot_synced_at',
            ]);
        });
    }
};
