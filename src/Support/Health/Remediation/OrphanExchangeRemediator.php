<?php

declare(strict_types=1);

namespace Kraite\Core\Support\Health\Remediation;

use Kraite\Core\Models\Account;
use Kraite\Core\Support\ApiDataMappers\Bitget\BitgetProductContext;
use Kraite\Core\Support\ValueObjects\ApiProperties;
use Kraite\Core\Trading\Exchange\Exchange;
use RuntimeException;
use Throwable;

final class OrphanExchangeRemediator
{
    public function cancelOrder(Account $account, string $orderId, string $symbol, bool $isAlgo): void
    {
        $properties = new ApiProperties;
        $properties->set('account', $account);
        $properties->set('options.symbol', $symbol);

        if ($account->apiSystem->canonical === 'bitget') {
            $context = BitgetProductContext::fromQuote($account->trading_quote);
            $properties->set('options.productType', $context->productType);
            $properties->set('options.marginCoin', $context->marginCoin);

            if ($isAlgo) {
                $properties->set('options.orderIdList', [[
                    'orderId' => $orderId,
                    'clientOid' => '',
                ]]);
                Exchange::forAccount($account)->withApi()->cancelPlanOrder($properties);

                return;
            }

            $properties->set('options.orderId', $orderId);
            Exchange::forAccount($account)->withApi()->cancelOrder($properties);

            return;
        }

        if ($isAlgo) {
            $properties->set('options.algoId', $orderId);
            Exchange::forAccount($account)->withApi()->cancelAlgoOrder($properties);

            return;
        }

        $properties->set('options.orderId', $orderId);
        Exchange::forAccount($account)->withApi()->cancelOrder($properties);
    }

    public function closePosition(Account $account, string $symbol, ?string $direction, string $quantity): void
    {
        if ($direction === null) {
            throw new RuntimeException("Orphan position key missing direction: {$symbol}");
        }

        if ($quantity === '0' || $quantity === '') {
            return;
        }

        $properties = new ApiProperties;
        $properties->set('account', $account);
        $properties->set('options.symbol', $symbol);

        if ($account->apiSystem->canonical === 'bitget') {
            $context = BitgetProductContext::fromQuote($account->trading_quote);
            $properties->set('options.productType', $context->productType);

            if ($account->isHedgeMode()) {
                $properties->set('options.holdSide', mb_strtolower($direction));
            }

            try {
                Exchange::forAccount($account)->withApi()->flashClosePosition($properties);
            } catch (Throwable $exception) {
                $message = $exception->getMessage();

                if (str_contains($message, '(code 25227)')
                    || str_contains($message, 'No position available to close')) {
                    return;
                }

                throw $exception;
            }

            return;
        }

        $properties->set('options.type', 'MARKET');
        $properties->set('options.side', $direction === 'LONG' ? 'SELL' : 'BUY');
        $properties->set('options.quantity', $quantity);

        if ($account->isHedgeMode()) {
            $properties->set('options.positionSide', $direction);
        } else {
            $properties->set('options.reduceOnly', 'true');
        }

        Exchange::forAccount($account)->withApi()->placeOrder($properties);
    }
}
