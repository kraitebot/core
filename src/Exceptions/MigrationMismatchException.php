<?php

declare(strict_types=1);

namespace Kraite\Core\Exceptions;

use RuntimeException;

final class MigrationMismatchException extends RuntimeException
{
    /**
     * @param  list<string>  $onlyLocal
     * @param  list<string>  $onlyProduction
     */
    public function __construct(
        public readonly array $onlyLocal,
        public readonly array $onlyProduction,
    ) {
        parent::__construct('Local and production migration histories differ.');
    }
}
