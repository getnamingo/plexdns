<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

final class BindServiceFake
{
    public static array $calls = [];

    public function __construct($config) {}

    public function createRRset($domain, $data): bool
    {
        self::$calls[] = ['create', $data];
        return true;
    }

    public function deleteRRset($domain, $name, $type, $value): bool
    {
        self::$calls[] = ['delete', $name, $type, $value];
        return true;
    }
}

class_alias(BindServiceFake::class, 'Namingo\\Cardo\\DNS\\Providers\\Bind');

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new Namingo\Cardo\DNS\Service($db);
$service->install();

$db->exec("INSERT INTO " . plexZonesTable() . " (client_id, config, domain_name, created_at, updated_at)
VALUES (1, '{\"provider\":\"Bind\"}', 'example.com', CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)");

$mxId = $service->addRecord([
    'provider' => 'Bind',
    'domain_name' => 'example.com',
    'record_name' => '@',
    'record_type' => 'MX',
    'record_value' => 'mail.example.com.',
    'record_priority' => 10,
    'record_ttl' => 300,
]);

$service->delRecord([
    'provider' => 'Bind',
    'domain_name' => 'example.com',
    'record_id' => $mxId,
]);

$mxCall = BindServiceFake::$calls[array_key_last(BindServiceFake::$calls)];
expect($mxCall[0] === 'delete', 'Expected BIND MX delete call.');
expect($mxCall[3] === '10 mail.example.com.', 'BIND MX deletion must include priority.');

$srvId = $service->addRecord([
    'provider' => 'Bind',
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
    'provider' => 'Bind',
    'domain_name' => 'example.com',
    'record_id' => $srvId,
]);

$srvCall = BindServiceFake::$calls[array_key_last(BindServiceFake::$calls)];
expect($srvCall[0] === 'delete', 'Expected BIND SRV delete call.');
expect(
    $srvCall[3] === '10 20 5060 sip.example.com.',
    'BIND SRV deletion must include priority, weight, port and target.'
);

echo "BIND Service structured deletion checks passed.\n";
