<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

$canonicalSymbols = [
    Namingo\Cardo\DNS\Service::class,
    Namingo\Cardo\DNS\Providers\Testing::class,
    Namingo\Cardo\DNS\Providers\DnsHostingProviderInterface::class,
    Namingo\Cardo\DNS\UnsupportedProviderException::class,
];

$legacySymbols = [
    'PlexDNS\\Service',
    'PlexDNS\\Providers\\Testing',
    'PlexDNS\\Providers\\DnsHostingProviderInterface',
    'PlexDNS\\UnsupportedProviderException',
];

foreach ($canonicalSymbols as $symbol) {
    if (
        !class_exists($symbol)
        && !interface_exists($symbol)
        && !enum_exists($symbol)
    ) {
        throw new RuntimeException("Canonical symbol failed to autoload: {$symbol}");
    }
}

foreach ($legacySymbols as $symbol) {
    if (
        !class_exists($symbol)
        && !interface_exists($symbol)
        && !enum_exists($symbol)
    ) {
        throw new RuntimeException("Legacy symbol failed to autoload: {$symbol}");
    }
}

if (!is_a('PlexDNS\\Service', Namingo\Cardo\DNS\Service::class, true)) {
    throw new RuntimeException('Legacy Service alias does not resolve to Cardo DNS.');
}

if (!is_a(
    'PlexDNS\\Providers\\Testing',
    Namingo\Cardo\DNS\Providers\Testing::class,
    true
)) {
    throw new RuntimeException('Legacy provider alias does not resolve to Cardo DNS.');
}

echo "Cardo DNS compatibility test passed.\n";
