<?php

declare(strict_types=1);

namespace Kraite\Core\Support;

use JsonException;
use Kraite\Core\Enums\PositionPresence;
use Kraite\Core\Models\Account;
use Kraite\Core\Models\Position;
use Kraite\Core\Support\ValueObjects\ApiResponse;

final readonly class PositionSnapshot
{
    /**
     * @param  array<array-key, array<string, mixed>>  $positions
     */
    private function __construct(
        private array $positions,
        private bool $valid,
    ) {}

    public static function fromApiResponse(Account $account, ApiResponse $apiResponse): self
    {
        $response = $apiResponse->response;

        if ($response === null || $response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
            return new self([], false);
        }

        try {
            $body = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return new self([], false);
        }

        if (! self::hasValidEnvelope($account->apiSystem->canonical, $body)) {
            return new self([], false);
        }

        $openPositionCount = self::openPositionCount($account->apiSystem->canonical, $body);

        if ($openPositionCount === null || $openPositionCount !== count($apiResponse->result)) {
            return new self([], false);
        }

        return self::fromValidatedResult($apiResponse->result);
    }

    /**
     * @param  array<array-key, mixed>  $positions
     */
    public static function fromValidatedResult(array $positions): self
    {
        foreach ($positions as $key => $position) {
            if (! is_array($position)) {
                return new self([], false);
            }

            $symbol = self::positionSymbol($key, $position);
            $quantity = self::positionQuantity($position);

            if ($symbol === null
                || $quantity === null
                || self::positionDirection($position, $quantity) === null) {
                return new self([], false);
            }
        }

        /** @var array<array-key, array<string, mixed>> $positions */
        return new self($positions, true);
    }

    public function isValid(): bool
    {
        return $this->valid;
    }

    public function presenceOf(Position $position): PositionPresence
    {
        if (! $this->valid) {
            return PositionPresence::Unknown;
        }

        $targetSymbol = self::normalizeSymbol((string) $position->parsed_trading_pair);
        $targetDirection = mb_strtoupper((string) $position->direction);

        foreach ($this->positions as $key => $positionData) {
            $symbol = self::positionSymbol($key, $positionData);

            if ($symbol === null) {
                return PositionPresence::Unknown;
            }

            if ($symbol !== $targetSymbol) {
                continue;
            }

            $quantity = self::positionQuantity($positionData);

            if ($quantity === null) {
                return PositionPresence::Unknown;
            }

            if (Math::equal($quantity, '0')) {
                continue;
            }

            $direction = self::positionDirection($positionData, $quantity);

            if ($direction === null) {
                return PositionPresence::Unknown;
            }

            if ($direction === $targetDirection) {
                return PositionPresence::Open;
            }
        }

        return PositionPresence::Flat;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function matchingPosition(Position $position): ?array
    {
        if ($this->presenceOf($position) !== PositionPresence::Open) {
            return null;
        }

        $targetSymbol = self::normalizeSymbol((string) $position->parsed_trading_pair);
        $targetDirection = mb_strtoupper((string) $position->direction);

        foreach ($this->positions as $key => $positionData) {
            $symbol = self::positionSymbol($key, $positionData);
            $quantity = self::positionQuantity($positionData);

            if ($symbol === $targetSymbol
                && $quantity !== null
                && ! Math::equal($quantity, '0')
                && self::positionDirection($positionData, $quantity) === $targetDirection) {
                return $positionData;
            }
        }

        return null;
    }

    private static function hasValidEnvelope(string $canonical, mixed $body): bool
    {
        if (! is_array($body)) {
            return false;
        }

        return match (mb_strtolower($canonical)) {
            'binance' => array_is_list($body)
                && self::rowsContain($body, ['symbol', 'positionAmt']),
            'bitget' => (string) ($body['code'] ?? '') === '00000'
                && self::hasValidBitgetRows($body),
            'bybit' => (int) ($body['retCode'] ?? -1) === 0
                && isset($body['result']['list'])
                && is_array($body['result']['list'])
                && array_is_list($body['result']['list'])
                && self::rowsContain($body['result']['list'], ['symbol', 'side', 'size']),
            'kucoin' => (string) ($body['code'] ?? '') === '200000'
                && isset($body['data'])
                && is_array($body['data'])
                && array_is_list($body['data'])
                && self::rowsContain($body['data'], ['symbol', 'currentQty', 'isOpen']),
            default => false,
        };
    }

    /**
     * @param  array<array-key, mixed>  $body
     */
    private static function openPositionCount(string $canonical, array $body): ?int
    {
        $canonical = mb_strtolower($canonical);
        $rows = match ($canonical) {
            'binance' => $body,
            'bitget' => self::bitgetRows($body),
            'kucoin' => $body['data'],
            'bybit' => $body['result']['list'],
            default => null,
        };

        if (! is_array($rows)) {
            return null;
        }

        $count = 0;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                return null;
            }

            $quantity = match ($canonical) {
                'binance' => $row['positionAmt'] ?? null,
                'bitget' => $row['total'] ?? null,
                'bybit' => $row['size'] ?? null,
                'kucoin' => $row['currentQty'] ?? null,
                default => null,
            };

            if (! is_numeric($quantity)) {
                return null;
            }

            if ($canonical === 'kucoin' && ($row['isOpen'] ?? false) !== true) {
                continue;
            }

            if (! Math::equal((string) $quantity, '0')) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<array-key, mixed>  $rows
     * @param  list<string>  $requiredKeys
     */
    private static function rowsContain(array $rows, array $requiredKeys): bool
    {
        foreach ($rows as $row) {
            if (! is_array($row)) {
                return false;
            }

            foreach ($requiredKeys as $requiredKey) {
                if (! array_key_exists($requiredKey, $row)) {
                    return false;
                }
            }
        }

        return true;
    }

    /** @param array<string, mixed> $body */
    private static function hasValidBitgetRows(array $body): bool
    {
        $rows = self::bitgetRows($body);

        if ($rows === null || ! self::rowsContain($rows, ['symbol', 'total'])) {
            return false;
        }

        foreach ($rows as $row) {
            if (! is_array($row)
                || (! array_key_exists('holdSide', $row) && ! array_key_exists('posSide', $row))) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<int, mixed>|null
     */
    private static function bitgetRows(array $body): ?array
    {
        $data = $body['data'] ?? null;

        if (! is_array($data)) {
            return null;
        }

        if (array_is_list($data)) {
            return $data;
        }

        $rows = $data['list'] ?? null;

        return is_array($rows) && array_is_list($rows) ? $rows : null;
    }

    /**
     * @param  array<string, mixed>  $positionData
     */
    private static function positionSymbol(int|string $key, array $positionData): ?string
    {
        $symbol = $positionData['symbol'] ?? (is_string($key) ? explode(':', $key, 2)[0] : null);

        if (! is_string($symbol) || $symbol === '') {
            return null;
        }

        return self::normalizeSymbol($symbol);
    }

    /**
     * @param  array<string, mixed>  $positionData
     */
    private static function positionQuantity(array $positionData): ?string
    {
        foreach (['positionAmt', 'size', 'total', 'currentQty', 'qty', 'available', 'contracts'] as $key) {
            if (! array_key_exists($key, $positionData)) {
                continue;
            }

            $quantity = $positionData[$key];

            return is_numeric($quantity) ? (string) $quantity : null;
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $positionData
     */
    private static function positionDirection(array $positionData, string $quantity): ?string
    {
        $side = mb_strtoupper((string) ($positionData['positionSide'] ?? $positionData['side'] ?? 'BOTH'));

        return match ($side) {
            'LONG', 'BUY' => 'LONG',
            'SHORT', 'SELL' => 'SHORT',
            'BOTH' => Math::lt($quantity, '0') ? 'SHORT' : 'LONG',
            default => null,
        };
    }

    private static function normalizeSymbol(string $symbol): string
    {
        return mb_strtoupper(mb_trim($symbol));
    }
}
