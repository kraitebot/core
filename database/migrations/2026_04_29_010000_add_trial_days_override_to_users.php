<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedSmallInteger('trial_days_override')
                ->nullable()
                ->after('trial_started_at')
                ->comment('Per-user override for trial duration. NULL = inherit subscription.trial_days. Lets the admin grant longer/shorter trials to specific users without changing the tier default.');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('trial_days_override');
        });
    }
};
