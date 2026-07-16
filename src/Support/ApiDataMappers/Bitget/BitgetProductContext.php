<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ApiDataMappers\Bitget;

use InvalidArgumentException;

final readonly class BitgetProductContext
{
    private const SUPPORTED_QUOTES = ['USDT', 'USDC'];

    private function __construct(
        public string $quote,
        public string $productType,
        public string $marginCoin,
    ) {}

    public static function fromQuote(?string $quote): self
    {
        $normalizedQuote = mb_strtoupper(trim((string) $quote));

        if (! in_array($normalizedQuote, self::SUPPORTED_QUOTES, true)) {
            $displayQuote = match (true) {
                $quote === null => 'null',
                $normalizedQuote === '' => 'empty',
                default => $normalizedQuote,
            };

            throw new InvalidArgumentException(
                "Unsupported Bitget futures quote [{$displayQuote}]. Supported quotes: USDT, USDC."
            );
        }

        return new self(
            quote: $normalizedQuote,
            productType: "{$normalizedQuote}-FUTURES",
            marginCoin: $normalizedQuote,
        );
    }

    /** @return list<string> */
    public static function supportedQuotes(): array
    {
        return self::SUPPORTED_QUOTES;
    }
}
