<?php

declare(strict_types=1);

namespace Namingo\Cardo\DNS;

/**
 * A caller supplied an incomplete or invalid request to the Service API.
 *
 * Extends RuntimeException to preserve compatibility with existing consumers
 * that already catch RuntimeException from Service methods.
 */
final class RequestValidationException extends \RuntimeException
{
}
