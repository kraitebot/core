<?php

declare(strict_types=1);

namespace Kraite\Core\Contracts;

use Kraite\Core\Support\ValueObjects\ApiProperties;
use Psr\Http\Message\ResponseInterface;

interface TaapiApiDriver
{
    public function getGroupedIndicatorsValues(ApiProperties $properties): ResponseInterface;

    public function getBulkIndicatorsValues(ApiProperties $properties): ResponseInterface;

    public function getIndicatorValues(ApiProperties $properties): ResponseInterface;

    public function baseUrl(): string;
}
