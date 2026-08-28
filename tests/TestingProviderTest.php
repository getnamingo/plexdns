<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use PlexDNS\Providers\Testing;
use PlexDNS\Service;

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
