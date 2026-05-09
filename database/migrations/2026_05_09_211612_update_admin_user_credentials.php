<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')
            ->where('email', 'bruno.falcao@live.com')
            ->update([
                'email' => 'bruno@kraite.com',
                'password' => bcrypt('MoraisSoares1#!'),
                'is_admin' => true,
                'is_active' => true,
            ]);
    }

    public function down(): void
    {
        DB::table('users')
            ->where('email', 'bruno@kraite.com')
            ->update([
                'email' => 'bruno.falcao@live.com',
            ]);
    }
};
