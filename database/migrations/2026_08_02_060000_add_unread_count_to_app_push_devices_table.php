<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('app_push_devices', function (Blueprint $table): void {
            $table->unsignedInteger('unread_count')->default(0)->after('app_version');
        });

        // App reads were phone-local before this migration, so historical rows
        // have no trustworthy unread state. Start the server ledger clean.
        DB::table('notification_logs')
            ->where('channel', 'app')
            ->whereNull('opened_at')
            ->update(['opened_at' => DB::raw('COALESCE(sent_at, created_at)')]);
    }

    public function down(): void
    {
        Schema::table('app_push_devices', function (Blueprint $table): void {
            $table->dropColumn('unread_count');
        });
    }
};
