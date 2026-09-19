<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PlexDNS\Providers\Cloudflare;
use PlexDNS\Providers\DNSimple;
use PlexDNS\Providers\Vultr;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $body, int $status = 200): Response {
    return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
}
function setProperty(object $object, string $name, mixed $value): void {
    (new ReflectionProperty($object, $name))->setValue($object, $value);
}
function provider(string $class, array $responses, array &$history): object {
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    if ($class === Cloudflare::class) {
        $p = new Cloudflare(['apikey' => 'test-token']);
        $adapter = (new ReflectionProperty($p, 'adapter'))->getValue($p);
        setProperty($adapter, 'client', new Client(['handler' => $stack, 'base_uri' => 'https://api.cloudflare.com/client/v4/']));
    } elseif ($class === DNSimple::class) {
        // Avoid the constructor's whoami request; exercise the real SDK thereafter.
        $p = (new ReflectionClass($class))->newInstanceWithoutConstructor();
        setProperty($p, 'client', new \Dnsimple\Client('test-token', ['handler' => $stack]));
        setProperty($p, 'account_id', 42);
    } else {
        $p = new Vultr(['apikey' => 'test-token']);
        $client = (new ReflectionProperty($p, 'client'))->getValue($p);
        $client->setClient(new Client(['handler' => $stack]));
        $http = (new ReflectionProperty($p, 'dnssecClient'))->getValue($p);
        setProperty($p, 'dnssecClient', new Client(['handler' => $stack] + $http->getConfig()));
    }
    return $p;
}
function payload(array $history, int $index): array {
    return json_decode((string)$history[$index]['request']->getBody(), true, 512, JSON_THROW_ON_ERROR);
}
function fails(callable $call): void {
    try { $call(); } catch (Exception $e) { return; }
    throw new RuntimeException('Expected API failure to propagate.');
}

$ds = ['key_tag' => 27933, 'algorithm' => 13, 'digest_type' => 2, 'digest' => str_repeat('ab', 32)];
$zone = response(['success' => true, 'result' => [['id' => 'zone-id']], 'result_info' => []]);
$cf = ['status' => 'pending'] + $ds;
$history = [];
$p = provider(Cloudflare::class, [$zone, response(['success' => true, 'result' => $cf])], $history);
expect($p->enableDNSSEC('example.com') === [$ds], 'Cloudflare pending enable exposes DS.');
expect($history[1]['request']->getMethod() === 'PATCH', 'Cloudflare PATCH.');
expect($history[1]['request']->getUri()->getPath() === '/client/v4/zones/zone-id/dnssec', 'Cloudflare zone ID path.');
expect(payload($history, 1) === ['status' => 'active'], 'Cloudflare activation payload.');
foreach (['active' => true, 'pending' => true, 'disabled' => false, 'pending-disabled' => false, 'error' => false] as $state => $enabled) {
    $history = [];
    $p = provider(Cloudflare::class, [$zone, response(['success' => true, 'result' => ['status' => $state] + $ds])], $history);
    $status = $p->getDNSSECStatus('example.com');
    expect($status['enabled'] === $enabled && $status['status'] === $state, 'Cloudflare state mapping.');
    expect($status['ds'] === ($enabled ? [$ds] : []), 'Cloudflare disabled/error state has no publishable DS.');
}
$history = [];
$p = provider(Cloudflare::class, [$zone, response(['success' => true, 'result' => ['status' => 'pending']])], $history);
expect($p->getDSRecords('example.com') === [], 'No invented DS while keys are pending.');
foreach (['disabled', 'pending-disabled'] as $state) {
    $history = [];
    $p = provider(Cloudflare::class, [$zone, response(['success' => true, 'result' => ['status' => $state]])], $history);
    expect($p->disableDNSSEC('example.com') === true, 'Cloudflare disable accepted.');
    expect(payload($history, 1) === ['status' => 'disabled'], 'Disable without deleting DNS records.');
}
$history = [];
$p = provider(Cloudflare::class, [$zone, response(['success' => false, 'errors' => [['message' => 'Permission denied']]])], $history);
fails(fn() => $p->enableDNSSEC('example.com'));

$history = [];
$p = provider(Cloudflare::class, [$zone, response(['success' => true, 'result' => ['status' => 'error']])], $history);
fails(fn() => $p->enableDNSSEC('example.com'));

function dnsimplePage(array $records, int $page = 1, int $total = 1): Response {
    return response(['data' => $records, 'pagination' => ['current_page' => $page, 'per_page' => 30, 'total_entries' => count($records), 'total_pages' => $total]]);
}
$record = ['id' => 1, 'domain_id' => 2, 'algorithm' => '13', 'digest_type' => '2', 'keytag' => '27933',
    'digest' => $ds['digest'], 'public_key' => null, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01'];
$di = ['enabled' => true, 'created_at' => '2026-01-01', 'updated_at' => '2026-01-01'];
$history = [];
$p = provider(DNSimple::class, [response(['data' => $di], 201), dnsimplePage([$record], 1, 2), dnsimplePage([$record], 2, 2)], $history);
expect($p->enableDNSSEC('example.com') === [$ds, $ds], 'DNSimple DS fields and pagination.');
expect($history[0]['request']->getMethod() === 'POST', 'DNSimple enable POST.');
expect($history[0]['request']->getUri()->getPath() === '/v2/42/domains/example.com/dnssec', 'DNSimple account path.');
expect($history[2]['request']->getUri()->getQuery() === 'page=2', 'DNSimple retrieves next page.');
foreach ([true, false] as $enabled) {
    $history = [];
    $responses = [response(['data' => ['enabled' => $enabled] + $di])];
    if ($enabled) $responses[] = dnsimplePage([$record]);
    $p = provider(DNSimple::class, $responses, $history);
    $status = $p->getDNSSECStatus('example.com');
    expect($status['enabled'] === $enabled && $status['ds'] === ($enabled ? [$ds] : []), 'DNSimple status.');
}
$history = [];
$p = provider(DNSimple::class, [dnsimplePage([['digest' => null, 'digest_type' => null, 'keytag' => null] + $record])], $history);
expect($p->getDSRecords('example.com') === [], 'DNSKEY-only delegation is not a DS.');
$history = [];
$p = provider(DNSimple::class, [new Response(204)], $history);
expect($p->disableDNSSEC('example.com') === true && $history[0]['request']->getMethod() === 'DELETE', 'DNSimple disable DELETE.');
$history = [];
$p = provider(DNSimple::class, [response(['message' => 'DNSSEC is not enabled'], 428)], $history);
fails(fn() => $p->disableDNSSEC('example.com'));

$vultrRecords = ['example.com IN DNSKEY 257 3 13 dGVzdA==',
    'example.com IN DS 27933 13 1 ' . str_repeat('cd', 20),
    'example.com. 3600 IN DS 27933 13 2 ' . $ds['digest']];
$vultrDs = [array_replace($ds, ['digest_type' => 1, 'digest' => str_repeat('cd', 20)]), $ds];
$history = [];
$p = provider(Vultr::class, [new Response(204), response(['dns_sec' => $vultrRecords])], $history);
expect($p->enableDNSSEC('example.com') === $vultrDs, 'Vultr filters DNSKEY, retains both DS digests.');
expect($history[0]['request']->getMethod() === 'PUT', 'Vultr requires PUT, not the SDK PATCH.');
expect(payload($history, 0) === ['dns_sec' => 'enabled'], 'Vultr only changes DNSSEC.');
expect($history[0]['request']->getHeaderLine('Authorization') === 'Bearer test-token', 'Vultr authenticated toggle.');
expect($history[1]['request']->getUri()->getPath() === '/v2/domains/example.com/dnssec', 'Vultr DS endpoint.');
foreach ([true, false] as $enabled) {
    $history = [];
    $responses = [response(['domain' => ['domain' => 'example.com', 'dns_sec' => $enabled ? 'enabled' : 'disabled']])];
    if ($enabled) $responses[] = response(['dns_sec' => $vultrRecords]);
    $p = provider(Vultr::class, $responses, $history);
    $status = $p->getDNSSECStatus('example.com');
    expect($status['enabled'] === $enabled && $status['ds'] === ($enabled ? $vultrDs : []), 'Vultr status.');
}
$history = [];
$p = provider(Vultr::class, [new Response(204)], $history);
expect($p->disableDNSSEC('example.com') === true, 'Vultr disable.');
expect(payload($history, 0) === ['dns_sec' => 'disabled'], 'Vultr disable payload.');
$history = [];
$p = provider(Vultr::class, [response(['dns_sec' => []])], $history);
expect($p->getDSRecords('example.com') === [], 'Vultr empty DS.');

// Permission failures must never become a successful toggle or a disabled status.
foreach ([Cloudflare::class, DNSimple::class, Vultr::class] as $class) {
    foreach (['enableDNSSEC', 'disableDNSSEC', 'getDNSSECStatus', 'getDSRecords'] as $method) {
        $history = [];
        $responses = $class === Cloudflare::class ? [$zone] : [];
        $responses[] = response(['message' => 'Forbidden', 'error' => 'Forbidden'], 403);
        $p = provider($class, $responses, $history);
        fails(fn() => $p->$method('example.com'));
    }
}
echo "Provider DNSSEC tests passed.\n";
