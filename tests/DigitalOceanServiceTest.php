<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final class DigitalOceanServiceFake
{
    public static array $calls = [];

    public function __construct($config) {}

    public function createDomain($name): array
    {
        return ['Id' => $name, 'name' => $name];
    }

    public function createRRset($domain, $data)
    {
        self::$calls[] = ['create', $data];
        return '98765';
    }

    public function modifyRRset($domain, $name, $type, $data): bool
    {
        self::$calls[] = ['update', $data];
        return true;
    }

    public function deleteRRset($domain, $name, $type, $value, $id = null): bool
    {
        self::$calls[] = ['delete', $id, $value];
        return true;
    }

    public function deleteDomain($name): bool
    {
        return true;
    }
}

class_alias(DigitalOceanServiceFake::class, 'PlexDNS\\Providers\\DigitalOcean');

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new PlexDNS\Service($db);
$service->install();

$order = [
    'client_id' => 1,
    'config' => json_encode([
        'provider' => 'DigitalOcean',
        'domain_name' => 'example.com',
        'apikey' => 'test',
    ], JSON_THROW_ON_ERROR),
];

$service->createDomain($order);
expect(
    $db->query('SELECT zoneId FROM ' . plexZonesTable())->fetchColumn() === 'example.com',
    'DigitalOcean zone name should be stored as zoneId.'
);

DigitalOceanServiceFake::$calls = [];
$id = $service->addRecord([
    'provider' => 'DigitalOcean',
    'domain_name' => 'example.com',
    'record_name' => '@',
    'record_type' => 'CAA',
    'record_value' => 'letsencrypt.org',
    'record_ttl' => 300,
    'record_flags' => 0,
    'record_tag' => 'issue',
]);
expect(
    $db->query('SELECT recordId FROM ' . plexRecordsTable() . ' WHERE id = ' . $id)->fetchColumn() === '98765',
    'Provider record ID must be persisted.'
);
expect(DigitalOceanServiceFake::$calls[0][1]['flags'] === 0, 'CAA flags forwarded.');
expect(DigitalOceanServiceFake::$calls[0][1]['tag'] === 'issue', 'CAA tag forwarded.');
expect(
    $db->query('SELECT value FROM ' . plexRecordsTable() . ' WHERE id = ' . $id)->fetchColumn() === '0 issue letsencrypt.org',
    'CAA must be stored locally as complete canonical RDATA.'
);

$service->updateRecord([
    'provider' => 'DigitalOcean',
    'domain_name' => 'example.com',
    'record_id' => $id,
    'record_name' => '@',
    'record_type' => 'CAA',
    'record_value' => 'pki.goog',
    'record_ttl' => 600,
    'record_flags' => 0,
    'record_tag' => 'issue',
]);
expect(DigitalOceanServiceFake::$calls[1][1]['record_id'] === '98765', 'Update uses saved provider record ID.');
expect(DigitalOceanServiceFake::$calls[1][1]['tag'] === 'issue', 'CAA update tag forwarded.');
expect(
    $db->query('SELECT value FROM ' . plexRecordsTable() . ' WHERE id = ' . $id)->fetchColumn() === '0 issue pki.goog',
    'Updated CAA must remain canonical locally.'
);

$service->delRecord([
    'provider' => 'DigitalOcean',
    'domain_name' => 'example.com',
    'record_id' => $id,
    'record_name' => '@',
    'record_type' => 'CAA',
    'record_value' => 'pki.goog',
]);
expect(DigitalOceanServiceFake::$calls[2][1] === '98765', 'Delete uses saved provider record ID.');

$capabilities = $service->getDNSSECCapabilities(['provider' => 'DigitalOcean']);
expect($capabilities['supported'] === false, 'DigitalOcean DNSSEC must be reported unsupported.');

$service->deleteDomain($order);

echo "DigitalOcean Service checks passed.\n";
