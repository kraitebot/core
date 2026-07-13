<?php

declare(strict_types=1);

namespace Kraite\Core\Support\ValueObjects;

use GuzzleHttp\Psr7\Response;

final class ApiResponse
{
    public ?Response $response;

    public array $result;

    public function __construct(?Response $response = null, ?array $result = [])
    {
        $this->response = $response;
        $this->result = $result;
    }
}
