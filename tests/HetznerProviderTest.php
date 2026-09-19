<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PlexDNS\Providers\Hetzner;

function expect(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}
function response(array $body, int $status = 201): Response {
    return new Response($status, [], json_encode($body, JSON_THROW_ON_ERROR));
}
function action(string $status = 'success'): array {
    return ['action' => ['id' => 7, 'status' => $status, 'error' => ['message' => 'test action failure']]];
}
function provider(array $responses, array &$history): Hetzner {
    $provider = new Hetzner(['apikey' => 'test-token'], new PDO('sqlite::memory:'));
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($history));
    $property = (new ReflectionClass($provider))->getProperty('client');
    $property->setValue($provider, new Client(['base_uri' => 'https://api.hetzner.cloud/v1/', 'handler' => $stack]));
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

// No local zone row exists yet. The real Cloud response is asynchronous and HTTP 201.
$history = [];
$p = provider([response(['zone' => ['id' => 42]] + action('running')), response(action(), 200), response(action())], $history);
$zone = $p->createDomain('EXAMPLE.COM.');
expect($zone['Id'] === '42', 'Service needs the returned zone ID.');
expect(payload($history, 0) === ['name' => 'example.com', 'mode' => 'primary'], 'Zone payload.');
expect($history[0]['request']->getHeaderLine('Authorization') === 'Bearer test-token', 'Cloud bearer token.');
expect($history[1]['request']->getUri()->getPath() === '/v1/zones/actions/7', 'Poll zone action.');
expect($p->deleteDomain('example.com') === true, 'Zone deletion.');
expect($history[2]['request']->getMethod() === 'DELETE', 'Zone DELETE.');

// Full DNS RDATA, apex and wildcard paths, boolean returns and no invented record ID.
foreach ([
    ['A', '', '192.0.2.1', [], '192.0.2.1'],
    ['AAAA', '*', '2001:0db8::1', [], '2001:db8::1'],
    ['MX', '@', 'Mail.Example.COM', ['priority' => 10], '10 mail.example.com.'],
    ['SRV', '_sip._tcp', 'Sip.Example.COM', ['priority' => 10, 'weight' => 20, 'port' => 5060], '10 20 5060 sip.example.com.'],
    ['TXT', '@', 'hello "world"', [], '"hello \\"world\\""'],
    ['TXT', '@', '', [], '""'],
    ['TXT', '@', str_repeat('x', 256), [], '"' . str_repeat('x', 255) . '" "x"'],
    ['CNAME', 'www', 'target.example.com', [], 'target.example.com.'],
] as [$type, $name, $value, $extra, $expected]) {
    $history = [];
    $p = provider([response(action()), response(action())], $history);
    $id = 'stale';
    $data = ['type' => $type, 'subname' => $name, 'ttl' => 300, 'records' => [$value]] + $extra;
    expect($p->createRRset('example.com', $data, $id) === true && $id === null, "$type creation result.");
    expect(payload($history, 0) === ['ttl' => 300, 'records' => [['value' => $expected]]], "$type payload.");
    $path = '/v1/zones/example.com/rrsets/' . rawurlencode($name === '' ? '@' : $name) . '/' . $type;
    expect($history[0]['request']->getUri()->getPath() === $path . '/actions/add_records', 'Append without replacing siblings.');
    expect($p->deleteRRset('example.com', $name, $type, $expected, 'obsolete-legacy-id') === true, 'Delete result.');
    expect(payload($history, 1) === ['records' => [['value' => $expected]]], 'Delete only selected value.');
    expect($history[1]['request']->getUri()->getPath() === $path . '/actions/remove_records', 'Ignore legacy IDs.');
}

$rrset = ['rrset' => ['ttl' => 300, 'records' => [
    ['value' => '192.0.2.1', 'comment' => 'selected'],
    ['value' => '192.0.2.2', 'comment' => 'keep'],
]]];
$history = [];
$p = provider([response($rrset, 200), response(action()), response(action())], $history);
expect($p->modifyRRset('example.com', 'www', 'A', ['ttl' => 600, 'records' => ['192.0.2.3'], 'old_value' => '192.0.2.1']) === true, 'Update result.');
expect(payload($history, 1) === ['records' => [
    ['value' => '192.0.2.3', 'comment' => 'selected'],
    ['value' => '192.0.2.2', 'comment' => 'keep'],
]], 'Preserve sibling records and comments.');
expect(payload($history, 2) === ['ttl' => 600], 'Explicit TTL action.');

foreach ([
    [[], 'old_value is required'],
    [['old_value' => '192.0.2.99'], 'not found'],
    [['old_value' => '192.0.2.1', 'records' => ['192.0.2.2']], 'already exists'],
] as [$extra, $error]) {
    $history = [];
    $p = provider([response($rrset, 200)], $history);
    fails(fn() => $p->modifyRRset('example.com', 'www', 'A', $extra + ['ttl' => 300, 'records' => ['192.0.2.3']]), $error);
    expect(count($history) === 1, 'Ambiguous/missing/duplicate value must not mutate DNS.');
}

$history = [];
$p = provider([], $history);
fails(fn() => $p->modifyRRset('example.com', 'www', 'A', ['ttl' => 5, 'records' => ['192.0.2.1']]), 'TTL');
expect($history === [], 'Validate TTL before mutation.');
foreach ([response(action('error')), response([]), response(['error' => ['message' => 'unauthorized']], 401), new Response(201, [], 'not json')] as $failure) {
    $history = [];
    $p = provider([$failure], $history);
    try {
        $p->createRRset('example.com', ['type' => 'A', 'subname' => 'www', 'ttl' => 300, 'records' => ['192.0.2.1']]);
    } catch (Throwable) {
        continue;
    }
    throw new RuntimeException('API/action/response failure must not report success.');
}

echo "Hetzner Cloud provider checks passed.\n";
