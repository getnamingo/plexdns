<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Namingo\Cardo\DNS\Providers\DigitalOcean;
use Namingo\Cardo\DNS\UnsupportedProviderException;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $body, int $status = 200): Response {
    return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
}
function provider(array $responses, array &$history): DigitalOcean {
    $provider = new DigitalOcean(['apikey' => 'test-token']);
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $property = (new ReflectionClass($provider))->getProperty('client');
    $property->setValue($provider, new Client([
        'base_uri' => 'https://api.digitalocean.com/v2/',
        'handler' => $stack,
    ]));
    return $provider;
}
function payload(array $history, int $index): array {
    return json_decode((string)$history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
function fails(callable $call, string $message = ''): void {
    try {
        $call();
    } catch (Throwable $error) {
        if ($message !== '') {
            expect(str_contains($error->getMessage(), $message), $error->getMessage());
        }
        return;
    }
    throw new RuntimeException('Expected failure.');
}

$history = [];
$p = provider([response(['domain' => [
    'name' => 'example.com',
    'ttl' => 1800,
    'zone_file' => '$ORIGIN example.com.\n',
]], 201)], $history);
$domain = $p->createDomain('EXAMPLE.COM.');
expect($domain['Id'] === 'example.com', 'Created domain must expose a Service-compatible ID.');
expect(payload($history, 0) === ['name' => 'example.com'], 'Domain create payload.');
expect($history[0]['request']->getHeaderLine('Authorization') === 'Bearer test-token', 'Bearer auth.');

$history = [];
$p = provider([
    response(['domains' => [
        ['name' => 'example.com'],
        ['name' => 'sub.example.com'],
    ]]),
], $history);
expect($p->getResponsibleDomain('host.sub.example.com') === 'sub.example.com', 'Longest matching zone.');
expect($history[0]['request']->getUri()->getQuery() === 'page=1&per_page=200', 'Domain pagination.');

$history = [];
$p = provider([response(['domain' => [
    'name' => 'example.com',
    'zone_file' => '$ORIGIN example.com.\n@ 1800 IN A 192.0.2.1\n',
]])], $history);
expect(str_contains($p->exportDomainAsZonefile('example.com'), '192.0.2.1'), 'Zone file export.');

foreach ([
    ['A', 'www', '192.0.2.1', [], ['type' => 'A', 'name' => 'www', 'data' => '192.0.2.1', 'ttl' => 300]],
    ['MX', '@', 'mail.example.com', ['priority' => 10], ['type' => 'MX', 'name' => '@', 'data' => 'mail.example.com', 'ttl' => 300, 'priority' => 10]],
    ['SRV', '_sip._tcp', 'sip.example.com', ['priority' => 10, 'weight' => 20, 'port' => 5060], ['type' => 'SRV', 'name' => '_sip._tcp', 'data' => 'sip.example.com', 'ttl' => 300, 'priority' => 10, 'weight' => 20, 'port' => 5060]],
    ['CAA', '@', '0 issue letsencrypt.org', [], ['type' => 'CAA', 'name' => '@', 'data' => 'letsencrypt.org', 'ttl' => 300, 'flags' => 0, 'tag' => 'issue']],
] as $index => [$type, $name, $value, $extra, $expected]) {
    $history = [];
    $id = 100 + $index;
    $p = provider([response(['domain_record' => ['id' => $id] + $expected], 201)], $history);
    $result = $p->createRRset('example.com', [
        'type' => $type,
        'subname' => $name,
        'ttl' => 300,
        'records' => [$value],
    ] + $extra);
    expect($result === (string)$id, "$type record ID.");
    expect(payload($history, 0) === $expected, "$type payload.");
}

$history = [];
$p = provider([response(['domain_record' => [
    'id' => 123,
    'type' => 'A',
    'name' => 'www',
    'data' => '192.0.2.2',
    'ttl' => 600,
]])], $history);
expect($p->modifyRRset('example.com', 'www', 'A', [
    'record_id' => '123',
    'ttl' => 600,
    'records' => ['192.0.2.2'],
]) === true, 'Record update.');
expect($history[0]['request']->getMethod() === 'PATCH', 'Update uses PATCH.');
expect($history[0]['request']->getUri()->getPath() === '/v2/domains/example.com/records/123', 'Update uses saved provider ID.');

$history = [];
$p = provider([new Response(204)], $history);
expect($p->deleteRRset('example.com', 'www', 'A', '192.0.2.2', '123') === true, 'Record delete.');
expect($history[0]['request']->getMethod() === 'DELETE', 'Delete method.');
expect($history[0]['request']->getUri()->getPath() === '/v2/domains/example.com/records/123', 'Delete uses saved provider ID.');

$history = [];
$p = provider([response(['domain_records' => [
    ['id' => 1, 'type' => 'A', 'name' => 'www', 'data' => '192.0.2.1', 'ttl' => 300],
    ['id' => 2, 'type' => 'AAAA', 'name' => 'www', 'data' => '2001:db8::1', 'ttl' => 300],
]])], $history);
$specific = $p->retrieveSpecificRRset('example.com', 'www', 'A');
expect(count($specific) === 1 && $specific[0]['id'] === 1, 'Specific record retrieval.');

// Without a persisted ID, structured RDATA must distinguish otherwise identical targets.
$history = [];
$p = provider([
    response(['domain_records' => [
        ['id' => 201, 'type' => 'SRV', 'name' => '_sip._tcp', 'data' => 'sip.example.com', 'ttl' => 300, 'priority' => 10, 'weight' => 5, 'port' => 5060],
        ['id' => 202, 'type' => 'SRV', 'name' => '_sip._tcp', 'data' => 'sip.example.com', 'ttl' => 300, 'priority' => 20, 'weight' => 10, 'port' => 5061],
    ]]),
    response(['domain_record' => ['id' => 202, 'type' => 'SRV', 'name' => '_sip._tcp', 'data' => 'new.example.com', 'ttl' => 300, 'priority' => 20, 'weight' => 10, 'port' => 5061]]),
], $history);
expect($p->modifyRRset('example.com', '_sip._tcp', 'SRV', [
    'old_value' => '20 10 5061 sip.example.com',
    'priority' => 20,
    'weight' => 10,
    'port' => 5061,
    'ttl' => 300,
    'records' => ['new.example.com'],
]) === true, 'Structured SRV fallback update.');
expect($history[1]['request']->getUri()->getPath() === '/v2/domains/example.com/records/202', 'SRV fallback must select the complete RDATA match.');

$history = [];
$p = provider([
    response(['domain_records' => [
        ['id' => 301, 'type' => 'CAA', 'name' => '@', 'data' => 'ca.example', 'ttl' => 300, 'flags' => 0, 'tag' => 'issue'],
        ['id' => 302, 'type' => 'CAA', 'name' => '@', 'data' => 'ca.example', 'ttl' => 300, 'flags' => 128, 'tag' => 'iodef'],
    ]]),
    new Response(204),
], $history);
expect($p->deleteRRset('example.com', '@', 'CAA', '128 iodef ca.example') === true, 'Structured CAA fallback delete.');
expect($history[1]['request']->getUri()->getPath() === '/v2/domains/example.com/records/302', 'CAA fallback must select the complete RDATA match.');

$history = [];
$p = provider([new Response(204)], $history);
expect($p->deleteDomain('example.com') === true, 'Domain deletion.');
expect($history[0]['request']->getUri()->getPath() === '/v2/domains/example.com', 'Domain delete path.');

$history = [];
$p = provider([], $history);
foreach (['enableDNSSEC', 'disableDNSSEC', 'getDNSSECStatus', 'getDSRecords'] as $method) {
    try {
        $p->$method('example.com');
        throw new RuntimeException('Expected DNSSEC operation to be unsupported.');
    } catch (UnsupportedProviderException) {
    }
}

$history = [];
$p = provider([], $history);
fails(fn() => $p->createRRset('example.com', [
    'type' => 'A', 'subname' => 'www', 'ttl' => 29, 'records' => ['192.0.2.1'],
]), 'TTL');

echo "DigitalOcean provider checks passed.\n";
