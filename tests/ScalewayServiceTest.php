<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final class ScalewayServiceFake
{
    public static array $calls = [];

    public function __construct($config) {}

    public function createDomain($name): array
    {
        return ['Id' => $name, 'domain' => $name, 'subdomain' => ''];
    }

    public function createRRset($domain, $data)
    {
        self::$calls[] = ['create', $data];
        return 'scw-record-1';
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

    public function enableDNSSEC($name): array { return []; }
    public function disableDNSSEC($name): bool { return true; }
    public function getDNSSECStatus($name): array { return ['enabled' => true, 'ds' => []]; }
    public function getDSRecords($name): array { return []; }
}

class_alias(ScalewayServiceFake::class, 'Namingo\Cardo\DNS\\Providers\\Scaleway');

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new Namingo\Cardo\DNS\Service($db);
$service->install();

$order = [
    'client_id' => 1,
    'config' => json_encode([
        'provider' => 'Scaleway',
        'domain_name' => 'example.com',
        'apikey' => 'test',
        'project_id' => 'project-1',
    ], JSON_THROW_ON_ERROR),
];

$service->createDomain($order);
expect(
    $db->query('SELECT zoneId FROM ' . plexZonesTable())->fetchColumn() === 'example.com',
    'Scaleway zone name should be stored as zoneId.'
);

ScalewayServiceFake::$calls = [];
$id = $service->addRecord([
    'provider' => 'Scaleway',
    'domain_name' => 'example.com',
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.1',
    'record_ttl' => 300,
]);
expect(
    $db->query('SELECT recordId FROM ' . plexRecordsTable() . ' WHERE id = ' . $id)->fetchColumn() === 'scw-record-1',
    'Scaleway record ID must be persisted.'
);

$service->updateRecord([
    'provider' => 'Scaleway',
    'domain_name' => 'example.com',
    'record_id' => $id,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
    'record_ttl' => 600,
]);
expect(ScalewayServiceFake::$calls[1][1]['record_id'] === 'scw-record-1', 'Update uses saved Scaleway ID.');

$service->delRecord([
    'provider' => 'Scaleway',
    'domain_name' => 'example.com',
    'record_id' => $id,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
]);
expect(ScalewayServiceFake::$calls[2][1] === 'scw-record-1', 'Delete uses saved Scaleway ID.');

$capabilities = $service->getDNSSECCapabilities(['provider' => 'Scaleway']);
expect($capabilities['supported'] === true, 'Scaleway DNSSEC should be supported.');
expect($capabilities['can_enable'] === true && $capabilities['can_disable'] === true, 'Scaleway DNSSEC toggle capabilities.');

$service->deleteDomain($order);

echo "Scaleway Service checks passed.\n";
