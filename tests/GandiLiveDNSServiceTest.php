<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final class GandiLiveDNSServiceFake
{
    public static array $calls = [];

    public function __construct($config) {}

    public function createDomain($name): array
    {
        return ['Id' => $name, 'fqdn' => $name];
    }

    public function createRRset($domain, $data): bool
    {
        self::$calls[] = ['create', $data];
        return true;
    }

    public function modifyRRset($domain, $name, $type, $data): bool
    {
        self::$calls[] = ['update', $name, $type, $data];
        return true;
    }

    public function deleteRRset($domain, $name, $type, $value): bool
    {
        self::$calls[] = ['delete', $name, $type, $value];
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

class_alias(GandiLiveDNSServiceFake::class, 'Namingo\Cardo\DNS\\Providers\\GandiLiveDNS');

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
        'provider' => 'GandiLiveDNS',
        'domain_name' => 'example.com',
        'apikey' => 'test',
    ], JSON_THROW_ON_ERROR),
];

$service->createDomain($order);

GandiLiveDNSServiceFake::$calls = [];
$id1 = $service->addRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.1',
    'record_ttl' => 300,
]);
$id2 = $service->addRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
    'record_ttl' => 300,
]);

$service->updateRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_id' => $id1,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.10',
    'record_ttl' => 600,
]);

$update = GandiLiveDNSServiceFake::$calls[2][3]['records'];
sort($update);
expect($update === ['192.0.2.10', '192.0.2.2'], 'Service preserves sibling RRset values on update.');
expect(
    (int)$db->query('SELECT ttl FROM ' . plexRecordsTable() . ' WHERE id = ' . $id1)->fetchColumn() === 600,
    'Updated Gandi RRset row TTL.'
);
expect(
    (int)$db->query('SELECT ttl FROM ' . plexRecordsTable() . ' WHERE id = ' . $id2)->fetchColumn() === 600,
    'Gandi sibling row TTL must be synchronized with the remote RRset.'
);


$service->delRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_id' => $id2,
    'record_name' => 'www',
    'record_type' => 'A',
    'record_value' => '192.0.2.2',
]);
expect(GandiLiveDNSServiceFake::$calls[3][3] === '192.0.2.2', 'Service deletes only the selected LiveDNS value.');


$mxId = $service->addRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_name' => '@',
    'record_type' => 'MX',
    'record_value' => 'mail.example.com.',
    'record_priority' => 10,
    'record_ttl' => 300,
]);
$service->delRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_id' => $mxId,
    'record_name' => '@',
    'record_type' => 'MX',
    'record_value' => 'mail.example.com.',
]);
expect(
    GandiLiveDNSServiceFake::$calls[array_key_last(GandiLiveDNSServiceFake::$calls)][3] === '10 mail.example.com.',
    'Gandi MX deletion must include priority in complete RDATA.'
);

$srvId = $service->addRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_name' => '_sip._tcp',
    'record_type' => 'SRV',
    'record_value' => 'sip.example.com.',
    'record_priority' => 10,
    'record_weight' => 20,
    'record_port' => 5060,
    'record_ttl' => 300,
]);
$service->delRecord([
    'provider' => 'GandiLiveDNS',
    'domain_name' => 'example.com',
    'record_id' => $srvId,
    'record_name' => '_sip._tcp',
    'record_type' => 'SRV',
    'record_value' => 'sip.example.com.',
]);
expect(
    GandiLiveDNSServiceFake::$calls[array_key_last(GandiLiveDNSServiceFake::$calls)][3]
        === '10 20 5060 sip.example.com.',
    'Gandi SRV deletion must include priority, weight, port and target.'
);

$cap = $service->getDNSSECCapabilities(['provider' => 'GandiLiveDNS']);
expect($cap['supported'] === true && $cap['can_enable'] === true, 'Gandi DNSSEC capability exposed.');

echo "Gandi LiveDNS Service checks passed.\n";
