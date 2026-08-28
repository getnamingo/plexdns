<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PlexDNS\Providers\Testing;
use PlexDNS\Providers\Bunny;
use PlexDNS\Service;
use PlexDNS\UnsupportedProviderException;

final class BunnySyncFake extends Bunny
{
    public function __construct(private array $records)
    {
        parent::__construct(['apikey' => 'test']);
    }

    public function retrieveAllRRsets($domainName): array
    {
        return $this->records;
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new Service($db);
$service->install();

$service->createDomain([
    'client_id' => 1,
    'config' => json_encode(['provider' => 'Testing', 'domain_name' => 'example.test'], JSON_THROW_ON_ERROR),
]);
$service->createDomain([
    'client_id' => 1,
    'config' => json_encode(['provider' => 'Testing', 'domain_name' => 'sub.example.test'], JSON_THROW_ON_ERROR),
]);

$recordId = $service->addRecord([
    'provider' => 'Testing',
    'domain_name' => 'example.test',
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.1',
    'record_ttl' => 300,
]);

$provider = new Testing($db);
expect(count($provider->listDomains()) === 2, 'Expected two SQLite-backed domains.');
expect($provider->getResponsibleDomain('host.sub.example.test') === 'sub.example.test', 'Expected longest matching domain.');
expect($provider->retrieveSpecificRRset('example.test', 'www', 'A')[0]['records'] === ['192.0.2.1'], 'Expected stored A record.');
expect($provider->exportDomainAsZonefile('example.test') === "www 300 IN A 192.0.2.1\n", 'Expected deterministic zonefile export.');

$service->updateRecord([
    'provider' => 'Testing',
    'domain_name' => 'example.test',
    'record_id' => $recordId,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
    'record_ttl' => 600,
]);
expect($provider->retrieveSpecificRRset('example.test', 'www', 'A')[0]['records'] === ['192.0.2.2'], 'Expected updated A record.');

$beforeSync = $db->query('SELECT * FROM ' . plexRecordsTable() . ' WHERE id = ' . $recordId)->fetch(PDO::FETCH_ASSOC);
expect(
    $service->sync(['provider' => 'Testing', 'domain_name' => 'example.test']) === 1,
    'Service sync must return the provider cached record count.'
);
$afterSync = $db->query('SELECT * FROM ' . plexRecordsTable() . ' WHERE id = ' . $recordId)->fetch(PDO::FETCH_ASSOC);
expect($afterSync === $beforeSync, 'Testing sync must not rewrite cached records.');

$now = date('Y-m-d H:i:s');
$zone = $db->prepare(
    'INSERT INTO ' . plexZonesTable() .
    ' (client_id, config, domain_name, zoneId, created_at, updated_at)' .
    ' VALUES (1, :config, :domain, :zone_id, :created_at, :updated_at)'
);
$zone->execute([
    ':config' => json_encode(['provider' => 'Bunny'], JSON_THROW_ON_ERROR),
    ':domain' => 'bunny.test',
    ':zone_id' => '123',
    ':created_at' => $now,
    ':updated_at' => $now,
]);
$bunnyZoneId = (int)$db->lastInsertId();
$stale = $db->prepare(
    'INSERT INTO ' . plexRecordsTable() .
    ' (domain_id, recordId, type, host, value, ttl, priority, created_at, updated_at)' .
    " VALUES (:domain_id, 'old', 'A', '', '192.0.2.10', 300, NULL, :created_at, :updated_at)"
);
$stale->execute([':domain_id' => $bunnyZoneId, ':created_at' => $now, ':updated_at' => $now]);

try {
    (new BunnySyncFake([['Id' => 1, 'Type' => 999]]))->sync($db, 'bunny.test');
    throw new RuntimeException('Expected an unsupported Bunny record type to fail.');
} catch (RuntimeException $exception) {
    expect(str_contains($exception->getMessage(), 'unsupported record type'), 'Expected the Bunny validation error.');
}
expect(
    $db->query('SELECT recordId FROM ' . plexRecordsTable() . ' WHERE domain_id = ' . $bunnyZoneId)->fetchColumn() === 'old',
    'Invalid provider data must not replace cached records.'
);

$bunny = new BunnySyncFake([
    ['Id' => 42, 'Type' => 0, 'Name' => '', 'Value' => '192.0.2.42', 'Ttl' => 0],
    [
        'Id' => 43,
        'Type' => 8,
        'Name' => '_sip._tcp',
        'Value' => 'sip.bunny.test',
        'Ttl' => 300,
        'Priority' => 10,
        'Weight' => 20,
        'Port' => 5060,
    ],
]);
expect($bunny->sync($db, 'bunny.test') === 2, 'Expected two synchronized Bunny records.');
$synced = $db->query(
    'SELECT recordId, type, value, ttl, priority FROM ' . plexRecordsTable() .
    ' WHERE domain_id = ' . $bunnyZoneId . ' ORDER BY recordId'
)->fetchAll(PDO::FETCH_ASSOC);
expect($synced[0]['ttl'] === 3600, 'Expected the Bunny TTL fallback.');
expect($synced[1]['value'] === '20 5060 sip.bunny.test', 'Expected compact SRV value storage.');
expect($synced[1]['priority'] === 10, 'Expected separate SRV priority storage.');
expect(
    str_contains($provider->exportDomainAsZonefile('bunny.test'), '_sip._tcp 300 IN SRV 10 20 5060 sip.bunny.test'),
    'Expected a complete SRV zonefile value.'
);

try {
    $service->sync(['provider' => 'AnycastDNS', 'domain_name' => 'bunny.test', 'apikey' => 'test']);
    throw new RuntimeException('Expected unsupported provider synchronization to fail.');
} catch (UnsupportedProviderException) {
}
$db->exec('DELETE FROM ' . plexZonesTable() . ' WHERE id = ' . $bunnyZoneId);

expect($service->enableDNSSEC(['provider' => 'Testing', 'domain_name' => 'example.test'])['enabled'], 'Expected DNSSEC enabled.');
expect($service->getDSRecords(['provider' => 'Testing', 'domain_name' => 'example.test']) === [], 'Testing provider must not invent DS records.');
expect($service->disableDNSSEC(['provider' => 'Testing', 'domain_name' => 'example.test']), 'Expected DNSSEC disabled.');
expect(!$service->getDNSSECStatus(['provider' => 'Testing', 'domain_name' => 'example.test'])['enabled'], 'Expected disabled DNSSEC status.');

$service->delRecord([
    'provider' => 'Testing',
    'domain_name' => 'example.test',
    'record_id' => $recordId,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
]);
expect($provider->retrieveAllRRsets('example.test') === [], 'Expected deleted record.');

$service->deleteDomain([
    'config' => json_encode(['provider' => 'Testing', 'domain_name' => 'example.test'], JSON_THROW_ON_ERROR),
]);
expect(count($provider->listDomains()) === 1, 'Expected deleted domain.');

try {
    new Testing(new PDO('sqlite:'));
    throw new RuntimeException('Expected temporary SQLite database to be rejected.');
} catch (InvalidArgumentException) {
}

$file = tempnam(sys_get_temp_dir(), 'plexdns-testing-');
try {
    new Testing(new PDO('sqlite:' . $file));
    throw new RuntimeException('Expected file-backed SQLite database to be rejected.');
} catch (InvalidArgumentException) {
} finally {
    unlink($file);
}

echo "Testing provider checks passed.\n";
