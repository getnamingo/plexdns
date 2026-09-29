<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PlexDNS\Providers\GandiLiveDNS;
use PlexDNS\UnsupportedProviderException;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $body, int $status = 200): Response {
    return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
}
function provider(array $responses, array &$history, array $extra = []): GandiLiveDNS {
    $provider = new GandiLiveDNS(['apikey' => 'test-token'] + $extra);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $property = (new ReflectionClass($provider))->getProperty('client');
    $property->setValue($provider, new Client([
        'base_uri' => 'https://api.gandi.net/v5/',
        'handler' => $stack,
    ]));
    return $provider;
}
function payload(array $history, int $index): array {
    return json_decode((string)$history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}

$history = [];
$p = provider([
    new Response(404, [], json_encode(['message' => 'not found'])),
    response(['message' => 'created'], 201),
    response(['fqdn' => 'example.com']),
], $history);
$domain = $p->createDomain('EXAMPLE.COM.');
expect($domain['Id'] === 'example.com', 'Domain ID.');
expect($history[0]['request']->getHeaderLine('Authorization') === 'Bearer test-token', 'Bearer auth by default.');
expect(payload($history, 1) === ['fqdn' => 'example.com'], 'Domain create payload.');

$history = [];
$p = provider([
    response([
        ['fqdn' => 'example.com'],
        ['fqdn' => 'sub.example.com'],
    ]),
], $history);
expect($p->getResponsibleDomain('www.sub.example.com') === 'sub.example.com', 'Longest matching LiveDNS zone.');

$history = [];
$p = provider([new Response(200, ['Content-Type' => 'text/plain'], "\$ORIGIN example.com.\n@ 300 IN A 192.0.2.1\n")], $history);
expect(str_contains($p->exportDomainAsZonefile('example.com'), '192.0.2.1'), 'Zone export as raw text.');

$history = [];
$p = provider([
    new Response(404, [], json_encode(['message' => 'not found'])),
    response(['message' => 'created'], 201),
], $history);
expect($p->createRRset('example.com', [
    'subname' => '@',
    'type' => 'CAA',
    'ttl' => 300,
    'flags' => 0,
    'tag' => 'issue',
    'records' => ['letsencrypt.org'],
]) === true, 'Create CAA RRset.');
expect(payload($history, 1) === [
    'rrset_name' => '@',
    'rrset_type' => 'CAA',
    'rrset_values' => ['0 issue letsencrypt.org'],
    'rrset_ttl' => 300,
], 'CAA encoded into presentation RDATA.');

$history = [];
$p = provider([
    response([
        'rrset_name' => 'www',
        'rrset_type' => 'A',
        'rrset_ttl' => 300,
        'rrset_values' => ['192.0.2.1'],
    ]),
    response(['message' => 'updated']),
], $history);
expect($p->createRRset('example.com', [
    'subname' => 'www',
    'type' => 'A',
    'ttl' => 600,
    'records' => ['192.0.2.2'],
]) === true, 'Merge new value into existing RRset.');
expect(payload($history, 1) === [
    'add_rrset_values' => ['192.0.2.2'],
    'rrset_ttl' => 600,
], 'Existing RRset is patched without replacing siblings.');

$history = [];
$p = provider([response(['message' => 'updated'])], $history);
expect($p->modifyRRset('example.com', 'www', 'A', [
    'ttl' => 300,
    'records' => ['192.0.2.10', '192.0.2.11'],
]) === true, 'Replace RRset with all values.');
expect(payload($history, 0) === [
    'rrset_values' => ['192.0.2.10', '192.0.2.11'],
    'rrset_ttl' => 300,
], 'Multi-value PUT preserved.');

$history = [];
$p = provider([response(['message' => 'updated'])], $history);
expect($p->deleteRRset('example.com', '_sip._tcp', 'SRV', '10 20 5060 sip.example.com.') === true, 'Delete single RRset value.');
expect(payload($history, 0) === [
    'remove_rrset_values' => ['10 20 5060 sip.example.com.'],
], 'Single-value deletion uses PATCH remove_rrset_values.');

$history = [];
$p = provider([], $history);
try {
    $p->deleteDomain('example.com');
    throw new RuntimeException('Expected unsupported domain removal.');
} catch (UnsupportedProviderException) {
}

$keys = [[
    'id' => 'key-1',
    'deleted' => false,
    'ds' => 'example.com. 3600 IN DS 12345 13 2 ABCDEF',
]];
$history = [];
$p = provider([
    response(['state' => 'inactive']),
    response(['message' => 'enabled']),
    response($keys),
    response(['state' => 'active']),
    response($keys),
    response(['message' => 'disabled']),
], $history);
expect($p->enableDNSSEC('example.com') === [[
    'key_tag' => 12345,
    'algorithm' => 13,
    'digest_type' => 2,
    'digest' => 'abcdef',
]], 'DNSSEC enable exposes DS records.');
$status = $p->getDNSSECStatus('example.com');
expect($status['enabled'] === true, 'DNSSEC active status.');
expect($p->disableDNSSEC('example.com') === true, 'DNSSEC disable.');

$history = [];
$p = provider([], $history, ['auth_scheme' => 'Apikey']);
$property = (new ReflectionClass($p))->getProperty('client');
expect($property->getValue($p) instanceof Client, 'Legacy auth scheme accepted.');

echo "Gandi LiveDNS provider checks passed.\n";
