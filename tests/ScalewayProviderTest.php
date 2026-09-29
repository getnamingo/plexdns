<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Namingo\Cardo\DNS\Providers\Scaleway;
use Namingo\Cardo\DNS\UnsupportedProviderException;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $body, int $status = 200): Response {
    return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
}
function provider(array $responses, array &$history, array $extra = []): Scaleway {
    $provider = new Scaleway(['apikey' => 'test-secret', 'project_id' => 'project-1'] + $extra);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $property = (new ReflectionClass($provider))->getProperty('client');
    $property->setValue($provider, new Client([
        'base_uri' => 'https://api.scaleway.com/domain/v2beta1/',
        'handler' => $stack,
    ]));
    return $provider;
}
function payload(array $history, int $index): array {
    return json_decode((string)$history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
function fails(callable $call, string $message): void {
    try { $call(); } catch (Throwable $error) {
        expect(str_contains($error->getMessage(), $message), $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected failure: ' . $message);
}

// Existing root zone is reused without trying to recreate it.
$zone = ['domain' => 'example.com', 'subdomain' => '', 'project_id' => 'project-1'];
$history = [];
$p = provider([response(['total_count' => 1, 'dns_zones' => [$zone]])], $history);
$created = $p->createDomain('EXAMPLE.COM.');
expect($created['Id'] === 'example.com', 'Root zone ID.');
expect($history[0]['request']->getHeaderLine('X-Auth-Token') === 'test-secret', 'Scaleway auth header.');

// New sub-zone resolves the parent managed domain and uses the CreateDNSZone API.
$history = [];
$p = provider([
    response(['total_count' => 0, 'dns_zones' => []]),
    response(['total_count' => 1, 'domains' => [['domain' => 'example.com']]]),
    response(['domain' => 'example.com', 'subdomain' => 'dev', 'project_id' => 'project-1'], 200),
], $history);
$created = $p->createDomain('dev.example.com');
expect($created['Id'] === 'dev.example.com', 'Sub-zone ID.');
expect(payload($history, 2) === [
    'domain' => 'example.com',
    'subdomain' => 'dev',
    'project_id' => 'project-1',
], 'Create sub-zone payload.');

// Zone export is BIND format.
$history = [];
$p = provider([new Response(200, ['Content-Type' => 'text/plain'], "\$ORIGIN example.com.\n@ 3600 IN A 192.0.2.1\n")], $history);
expect(str_contains($p->exportDomainAsZonefile('example.com'), '192.0.2.1'), 'Zone export.');
expect($history[0]['request']->getUri()->getQuery() === 'format=bind', 'BIND export format.');

// Add records through Scaleway's PATCH change-set API, preserving SRV semantics.
$history = [];
$p = provider([response(['records' => [[
    'id' => 'record-1',
    'type' => 'SRV',
    'name' => '_sip._tcp',
    'data' => '20 5060 sip.example.com.',
    'priority' => 10,
    'ttl' => 300,
]]])], $history);
$id = $p->createRRset('example.com', [
    'type' => 'SRV',
    'subname' => '_sip._tcp',
    'ttl' => 300,
    'priority' => 10,
    'weight' => 20,
    'port' => 5060,
    'records' => ['sip.example.com.'],
]);
expect($id === 'record-1', 'Created provider record ID.');
expect($history[0]['request']->getMethod() === 'PATCH', 'Record mutation uses PATCH.');
expect(payload($history, 0) === [
    'changes' => [[
        'add' => ['records' => [[
            'data' => '20 5060 sip.example.com.',
            'name' => '_sip._tcp',
            'priority' => 10,
            'ttl' => 300,
            'type' => 'SRV',
        ]]],
    ]],
    'return_all_records' => true,
    'disallow_new_zone_creation' => true,
], 'Scaleway add change payload.');

// Update and delete use the persisted UUID.
$history = [];
$p = provider([
    response(['records' => []]),
    response(['records' => []]),
], $history);
expect($p->modifyRRset('example.com', 'www', 'A', [
    'record_id' => 'record-42',
    'ttl' => 600,
    'records' => ['192.0.2.42'],
]) === true, 'Record update.');
expect(payload($history, 0)['changes'][0]['set']['id'] === 'record-42', 'Update uses saved ID.');
expect($p->deleteRRset('example.com', 'www', 'A', '192.0.2.42', 'record-42') === true, 'Record delete.');
expect(payload($history, 1)['changes'][0] === ['delete' => ['id' => 'record-42']], 'Delete uses saved ID.');

// set.records must preserve every requested RRset value.
$history = [];
$p = provider([response(['records' => []])], $history);
expect($p->modifyRRset('example.com', 'www', 'A', [
    'record_id' => 'record-multi',
    'ttl' => 300,
    'records' => ['192.0.2.10', '192.0.2.11'],
]) === true, 'Multi-value RRset update.');
$setRecords = payload($history, 0)['changes'][0]['set']['records'];
expect(count($setRecords) === 2, 'Scaleway set must preserve all RRset values.');
expect($setRecords[0]['data'] === '192.0.2.10' && $setRecords[1]['data'] === '192.0.2.11', 'Scaleway multi-value payload.');

$history = [];
$p = provider([response(['records' => []])], $history);
expect($p->modifyBulkRRsets('example.com', [[
    'subname' => '@',
    'type' => 'CAA',
    'record_id' => 'record-caa',
    'ttl' => 300,
    'flags' => 0,
    'tag' => 'issue',
    'records' => ['letsencrypt.org', 'pki.goog'],
]]) === true, 'Bulk multi-value CAA update.');
$bulkRecords = payload($history, 0)['changes'][0]['set']['records'];
expect(count($bulkRecords) === 2, 'Bulk Scaleway set must preserve all RRset values.');
expect($bulkRecords[0]['data'] === '0 issue letsencrypt.org', 'Scaleway CAA split fields are encoded.');
expect($bulkRecords[1]['data'] === '0 issue pki.goog', 'Scaleway CAA encoding applies to every value.');

// Full supported record family includes Scaleway-specific modern types.
$history = [];
$p = provider([], $history);
fails(fn() => $p->createRRset('example.com', [
    'type' => 'BOGUS', 'subname' => '', 'ttl' => 300, 'records' => ['x'],
]), 'Unsupported Scaleway DNS record type');

// Root zones cannot be independently deleted; sub-zones can.
$history = [];
$p = provider([response(['total_count' => 1, 'domains' => [['domain' => 'example.com']]])], $history);
try {
    $p->deleteDomain('example.com');
    throw new RuntimeException('Expected root delete to be unsupported.');
} catch (UnsupportedProviderException) {
}
$history = [];
$p = provider([response([])], $history, ['parent_domain' => 'example.com']);
expect($p->deleteDomain('dev.example.com') === true, 'Sub-zone delete.');
expect($history[0]['request']->getUri()->getPath() === '/domain/v2beta1/dns-zones/dev.example.com', 'Sub-zone delete path.');

// DNSSEC: enable, status/DS mapping, disable.
$rawDs = [[
    'key_id' => 27933,
    'algorithm' => 'ecdsap256sha256',
    'digest' => ['type' => 'sha_256', 'digest' => str_repeat('ab', 32)],
]];
$expectedDs = [[
    'key_tag' => 27933,
    'algorithm' => 13,
    'digest_type' => 2,
    'digest' => str_repeat('ab', 32),
]];
$history = [];
$p = provider([
    response(['domain' => ['domain' => 'example.com', 'dnssec' => ['status' => 'enabling', 'ds_records' => $rawDs]]]),
    response(['domain' => 'example.com', 'dnssec' => ['status' => 'enabled', 'ds_records' => $rawDs]]),
    response([]),
], $history, ['parent_domain' => 'example.com']);
expect($p->enableDNSSEC('example.com') === $expectedDs, 'Enable exposes DS.');
$status = $p->getDNSSECStatus('example.com');
expect($status['enabled'] === true && $status['ds'] === $expectedDs, 'DNSSEC status and DS.');
expect($p->disableDNSSEC('example.com') === true, 'DNSSEC disable.');
expect($history[0]['request']->getUri()->getPath() === '/domain/v2beta1/domains/example.com/enable-dnssec', 'Enable endpoint.');
expect($history[2]['request']->getUri()->getPath() === '/domain/v2beta1/domains/example.com/disable-dnssec', 'Disable endpoint.');

$history = [];
$p = provider([], $history, ['parent_domain' => 'example.com']);
try {
    $p->enableDNSSEC('dev.example.com');
    throw new RuntimeException('Expected sub-zone DNSSEC toggle to be unsupported.');
} catch (UnsupportedProviderException) {
}

echo "Scaleway provider checks passed.\n";
