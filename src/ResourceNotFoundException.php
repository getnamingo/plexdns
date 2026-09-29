<?php

declare(strict_types=1);

namespace Namingo\Cardo\DNS;

/**
 * A requested local Cardo resource does not exist.
 *
 * API frontends may safely map this to a non-retryable 404 response while
 * keeping provider/database operational failures as opaque 5xx responses.
 */
final class ResourceNotFoundException extends \RuntimeException
{
}
