<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Aligns the `api_data_stream.idempotency_key` column comment with the
 * actual implementation in ProcessUserDataEventJob::buildIdempotencyKey().
 *
 * The original migration's comment said the key was composed of
 * `(account_id : api_system_id : raw_event_type : event_time : exchange_order_id)`.
 * The implementation actually does:
 *   md5("{$accountId}|{$apiSystemId}|{json_encode($rawPayload)}")
 *
 * Hashing the full payload is intentional — it dedupes even payload
 * variants we haven't examined yet — but the comment-vs-code drift made
 * operational reasoning harder. A maintainer reading the table comment
 * expected event-time/order-id dedupe semantics; the actual behaviour is
 * sensitive to any payload-field difference and to JSON key order.
 *
 * Implementation note: uses raw ALTER TABLE rather than Schema's
 * `->change()` helper because change() re-emits the existing UNIQUE
 * index, which would collide with the original migration's
 * `api_data_stream_idempotency_key_unique`. A column-comment-only
 * update is a metadata operation that doesn't touch indexes or data.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('api_data_stream')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `api_data_stream` MODIFY `idempotency_key` VARCHAR(255) NOT NULL '.
            "COMMENT 'Dedup guard. Computed as md5(\"{accountId}|{apiSystemId}|{json_encode(rawPayload)}\") in ProcessUserDataEventJob::buildIdempotencyKey(). Re-delivered frames during reconnects or worker retries hit the UNIQUE and are silently skipped. Sensitive to JSON key order — same logical event with different field ordering would NOT collide.'"
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('api_data_stream')) {
            return;
        }

        DB::statement(
            'ALTER TABLE `api_data_stream` MODIFY `idempotency_key` VARCHAR(255) NOT NULL '.
            "COMMENT 'Dedup guard. Composed of (account_id : api_system_id : raw_event_type : event_time : exchange_order_id). Re-delivered frames during reconnects or worker retries hit the UNIQUE and are silently skipped.'"
        );
    }
};
