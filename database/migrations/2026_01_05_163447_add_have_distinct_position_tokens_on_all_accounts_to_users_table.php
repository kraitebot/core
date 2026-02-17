<?php

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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('have_distinct_position_tokens_on_all_accounts')
                ->default(false)
                ->after('can_trade')
                ->comment('If true, an active position token will not be repeated at all even if the user have several accounts');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('have_distinct_position_tokens_on_all_accounts');
        });
    }
};
