<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('binance_listen_keys', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')
                ->unique()
                ->constrained('accounts')
                ->restrictOnDelete()
                ->comment('FK to accounts. One row per Binance account that participates in the user data stream daemon. UNIQUE because Binance has at most one active listenKey per API key at any time.');

            $table->text('listen_key')
                ->comment('The Binance-issued listenKey currently associated with this account. The daemon uses it to open wss://fstream.binance.com/ws/<listenKey>. Encrypted by the model cast.');

            $table->timestamp('last_created_at', 3)
                ->nullable()
                ->comment('When the current listenKey was first obtained from Binance via REST POST /fapi/v1/listenKey.');

            $table->timestamp('last_keep_alive_at', 3)
                ->nullable()
                ->comment('When the current listenKey was last successfully kept alive via REST PUT /fapi/v1/listenKey. Binance auto-expires keys after 60 minutes without keepalive.');

            $table->string('last_keep_alive_status', 20)
                ->nullable()
                ->comment('Outcome of the most recent keepalive attempt: success / failure. Failure rolls into failure_count for alert thresholding.');

            $table->unsignedTinyInteger('failure_count')
                ->default(0)
                ->comment('Consecutive keepalive failures since the last success. Resets to zero on a successful keepalive. Used to drive operator alerts when the keepalive cron is unable to refresh.');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('binance_listen_keys');
    }
};
