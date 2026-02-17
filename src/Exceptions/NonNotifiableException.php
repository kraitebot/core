<?php

declare(strict_types=1);

namespace Kraite\Core\Exceptions;

use Exception;

/**
 * Type of exception that will work as a normal runtime exception
 * but on the BaseQueableJob it will not notify the admins under
 * the reportAndFail() method.
 */
final class NonNotifiableException extends Exception {}
