<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final class PowerDNSServiceFake
{
    public static array $calls = [];

    public function __construct($config) {}

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
}

class_alias(PowerDNSServiceFake::class, 'Namingo\Cardo\DNS\\Providers\\PowerDNS');

function expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new Namingo\Cardo\DNS\Service($db);
$service->install();

$db->exec("INSERT INTO " . plexZonesTable() . " (client_id, config, domain_name, created_at, updated_at)
VALUES (1, '{\"provider\":\"PowerDNS\"}', 'example.com', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

$id = $service->addRecord([
    'provider' => 'PowerDNS',
    'domain_name' => 'example.com',
    'record_name' => '_sip._tcp',
    'record_type' => 'SRV',
    'record_value' => 'sip-old.example.com.',
    'record_priority' => 10,
    'record_weight' => 20,
    'record_port' => 5060,
    'record_ttl' => 300,
]);

$service->updateRecord([
    'provider' => 'PowerDNS',
    'domain_name' => 'example.com',
    'record_id' => $id,
    'record_name' => '_sip._tcp',
    'record_type' => 'SRV',
    'record_value' => 'sip-new.example.com.',
    'record_priority' => 30,
    'record_weight' => 40,
    'record_port' => 5070,
    'record_ttl' => 600,
]);

$call = PowerDNSServiceFake::$calls[array_key_last(PowerDNSServiceFake::$calls)];
expect($call[0] === 'update', 'Expected PowerDNS update call.');
expect($call[3]['old_value'] === '20 5060 sip-old.example.com.', 'Old local SRV value forwarded.');
expect($call[3]['old_priority'] === 10, 'Old SRV priority forwarded separately.');
expect($call[3]['priority'] === 30, 'New SRV priority remains separate.');


$deleteId = $service->addRecord([
    'provider' => 'PowerDNS',
    'domain_name' => 'example.com',
    'record_name' => '_xmpp._tcp',
    'record_type' => 'SRV',
    'record_value' => 'xmpp.example.com.',
    'record_priority' => 5,
    'record_weight' => 10,
    'record_port' => 5222,
    'record_ttl' => 300,
]);

$service->delRecord([
    'provider' => 'PowerDNS',
    'domain_name' => 'example.com',
    'record_id' => $deleteId,
]);

$deleteCall = PowerDNSServiceFake::$calls[array_key_last(PowerDNSServiceFake::$calls)];
expect($deleteCall[0] === 'delete', 'Expected PowerDNS delete call.');
expect(
    $deleteCall[3] === '5 10 5222 xmpp.example.com.',
    'PowerDNS SRV deletion must use complete priority/weight/port/target RDATA.'
);

echo "PowerDNS Service structured update/delete checks passed.\n";
