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
        Schema::table('users', function (Blueprint $table): void {
            $table->timestamp('terms_accepted_at')
                ->nullable()
                ->after('email_verified_at')
                ->comment('When this trader accepted the Terms and Conditions during registration.');

            $table->string('terms_version', 32)
                ->nullable()
                ->after('terms_accepted_at')
                ->comment('Which published version of the Terms and Conditions they accepted.');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn(['terms_accepted_at', 'terms_version']);
        });
    }
};
