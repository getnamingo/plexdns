<?php

declare(strict_types=1);

/**
 * Backward compatibility for the historical PlexDNS namespace.
 *
 * Namingo\Cardo\DNS is the canonical namespace. The Composer package name
 * remains namingo/plexdns, and existing applications may continue using
 * PlexDNS without source changes.
 */
spl_autoload_register(static function (string $class): void {
    $legacyPrefix = 'PlexDNS\\';
    $canonicalPrefix = 'Namingo\\Cardo\\DNS\\';

    if (!str_starts_with($class, $legacyPrefix)) {
        return;
    }

    $canonicalClass = $canonicalPrefix . substr($class, strlen($legacyPrefix));

    if (
        class_exists($canonicalClass)
        || interface_exists($canonicalClass)
        || enum_exists($canonicalClass)
    ) {
        class_alias($canonicalClass, $class);
    }
});
