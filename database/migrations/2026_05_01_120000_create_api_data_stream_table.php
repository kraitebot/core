<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('api_data_stream', function (Blueprint $table) {
            $table->id();

            $table->foreignId('account_id')
                ->constrained('accounts')
                ->restrictOnDelete()
                ->comment('FK to accounts. Every frame is per-account: the daemon knows which account each WS belongs to before any payload is decoded.');

            $table->foreignId('api_system_id')
                ->constrained('api_systems')
                ->restrictOnDelete()
                ->comment('FK to api_systems. Distinguishes Binance / Bitget / Bybit / KuCoin frames in a single shared table — exchange differentiation lives here, not in separate tables.');

            $table->string('raw_event_type', 64)
                ->comment('Exchange-native event type preserved verbatim. Examples: ORDER_TRADE_UPDATE (Binance), orders (Bitget), tradeOrders (KuCoin), order (Bybit).');

            $table->string('event_type', 40)
                ->comment('Kraite normalized vocabulary across exchanges: order_update / execution / position_update / account_update / margin_call / listen_key_expired / other.');

            $table->string('exchange_order_id', 64)
                ->nullable()
                ->comment('Extracted from payload at write time via the per-exchange data mapper. Null for events not scoped to a single order (e.g. account_update, margin_call).');

            $table->string('client_order_id', 64)
                ->nullable()
                ->comment('Extracted from payload at write time. Used as fallback Order lookup key when exchange_order_id is missing.');

            $table->string('symbol', 40)
                ->nullable()
                ->comment('Trading pair (e.g. BTCUSDT). Extracted from payload at write time. Null on events not scoped to a symbol.');

            $table->string('status', 40)
                ->nullable()
                ->comment('Exchange-native status string preserved verbatim. Audit fidelity: FILLED (Binance) / filled (Bitget) / Filled (Bybit) all kept as-is.');

            $table->string('normalized_status', 20)
                ->nullable()
                ->comment('Kraite canonical status, mapped from native via the per-exchange mapper. Vocabulary matches Order::status exactly: NEW / PARTIALLY_FILLED / FILLED / CANCELED / EXPIRED / REJECTED / UNKNOWN.');

            $table->decimal('price', 20, 8)
                ->nullable()
                ->comment('Order stated price (the price the order was placed at). Null for non-order events.');

            $table->decimal('average_price', 20, 8)
                ->nullable()
                ->comment('Average fill price across all fills accumulated for this order so far. Null for non-order events.');

            $table->decimal('original_quantity', 20, 8)
                ->nullable()
                ->comment('Order size as originally placed.');

            $table->decimal('filled_quantity', 20, 8)
                ->nullable()
                ->comment('Cumulative filled quantity at the time of this event.');

            $table->decimal('last_filled_price', 20, 8)
                ->nullable()
                ->comment('Price of the specific fill that triggered this event. Null on events that are not fill notifications.');

            $table->decimal('last_filled_quantity', 20, 8)
                ->nullable()
                ->comment('Quantity of the specific fill that triggered this event. Null on events that are not fill notifications.');

            $table->timestamp('event_time', 3)
                ->nullable()
                ->comment('Exchange-claimed event time at millisecond precision, extracted from payload. Null when the exchange does not include one.');

            $table->timestamp('received_at', 3)
                ->comment('Daemon wall-clock when the frame was received from the WebSocket. Always set.');

            $table->json('raw_payload')
                ->comment('Full untouched frame for forensic queries that go deeper than the extracted columns. Discovery of new event types relies on this column.');

            $table->string('idempotency_key', 255)
                ->unique()
                ->comment('Dedup guard. Composed of (account_id : api_system_id : raw_event_type : event_time : exchange_order_id). Re-delivered frames during reconnects or worker retries hit the UNIQUE and are silently skipped.');

            $table->timestamps();

            $table->index(['account_id', 'received_at'], 'api_data_stream_account_received_idx');
            $table->index(['api_system_id', 'event_type', 'received_at'], 'api_data_stream_system_event_idx');
            $table->index('exchange_order_id', 'api_data_stream_exchange_order_idx');
            $table->index(['symbol', 'received_at'], 'api_data_stream_symbol_received_idx');
            $table->index(['normalized_status', 'received_at'], 'api_data_stream_norm_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('api_data_stream');
    }
};
