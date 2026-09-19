<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

// Exercise Service's persistence/order and RDATA mapping without provider traffic.
final class HetznerServiceFake {
    public static array $calls = [];
    public static bool $fail = false;
    public function __construct($config, PDO $db) {}
    public function createDomain($name): array {
        return ['Id' => '42', 'zone' => ['id' => 42]];
    }
    public function createRRset($domain, $data, &$createdRecordId = null): bool {
        $createdRecordId = null;
        return true;
    }
    public function modifyRRset($domain, $name, $type, $data): bool {
        self::$calls[] = ['update', $data];
        if (self::$fail) throw new RuntimeException('test action failure');
        return true;
    }
    public function deleteRRset($domain, $name, $type, $value, $id = null): bool {
        self::$calls[] = ['delete', $value];
        if (self::$fail) throw new RuntimeException('test action failure');
        return true;
    }
    public function deleteDomain($name): bool {
        if (self::$fail) throw new RuntimeException('test action failure');
        return true;
    }
}
class_alias(HetznerServiceFake::class, 'PlexDNS\\Providers\\Hetzner');
function expect(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$db = new PDO('sqlite::memory:');
$service = new PlexDNS\Service($db);
$service->install();
$order = ['client_id' => 1, 'config' => json_encode(['provider' => 'Hetzner', 'domain_name' => 'example.com', 'apikey' => 'test'])];
$service->createDomain($order);
expect($db->query('SELECT zoneId FROM ' . plexZonesTable())->fetchColumn() === '42', 'Save zone ID after provider creation.');
foreach ([
    ['A', 'www', '192.0.2.1', '192.0.2.2', [], '192.0.2.1', '192.0.2.2'],
    ['MX', '', 'mail.example.com', 'new.example.com', ['record_priority' => 10], '10 mail.example.com', '20 new.example.com'],
    ['SRV', '_sip._tcp', 'sip.example.com', 'new.example.com', ['record_priority' => 10, 'record_weight' => 20, 'record_port' => 5060], '10 20 5060 sip.example.com', '20 20 5060 new.example.com'],
] as [$type, $name, $old, $new, $extra, $oldRdata, $newRdata]) {
    $data = ['provider' => 'Hetzner', 'domain_name' => 'example.com', 'record_name' => $name,
        'record_type' => $type, 'record_value' => $old, 'record_ttl' => 300] + $extra;
    $id = $service->addRecord($data);
    $siblingValue = $type === 'A' ? '192.0.2.99' : 'backup.example.com';
    $siblingId = $service->addRecord(array_replace($data, ['record_value' => $siblingValue]));
    expect($db->query('SELECT recordId FROM ' . plexRecordsTable() . ' WHERE id = ' . $id)->fetchColumn() === null, 'No fabricated record IDs.');
    HetznerServiceFake::$calls = [];
    $update = array_replace($data, ['record_id' => $id, 'record_value' => $new, 'record_ttl' => 600, 'record_priority' => 20]);
    $service->updateRecord($update);
    expect(HetznerServiceFake::$calls[0][1]['old_value'] === $oldRdata, 'Forward saved old RDATA, including old priority.');
    expect((int)$db->query('SELECT ttl FROM ' . plexRecordsTable() . ' WHERE id = ' . $siblingId)->fetchColumn() === 600, 'Keep sibling TTLs consistent.');
    $service->delRecord($data + ['record_id' => $id]);
    expect(HetznerServiceFake::$calls[1] === ['delete', $newRdata], 'Delete using saved current RDATA, not stale caller value.');
    expect((int)$db->query('SELECT COUNT(*) FROM ' . plexRecordsTable() . ' WHERE id = ' . $siblingId)->fetchColumn() === 1, 'Keep sibling local row.');

    HetznerServiceFake::$fail = true;
    $before = $db->query('SELECT * FROM ' . plexRecordsTable() . ' WHERE id = ' . $siblingId)->fetch(PDO::FETCH_ASSOC);
    foreach (['updateRecord', 'delRecord'] as $method) {
        try { $service->$method(array_replace($update, ['record_id' => $siblingId])); }
        catch (RuntimeException $error) {
            expect(str_contains($error->getMessage(), 'test action failure'), 'Surface provider failure.');
            expect($db->query('SELECT * FROM ' . plexRecordsTable() . ' WHERE id = ' . $siblingId)->fetch(PDO::FETCH_ASSOC) === $before, 'Provider failure must not change local row.');
            continue;
        }
        throw new RuntimeException('Provider failure must propagate.');
    }
    HetznerServiceFake::$fail = false;
}
$service->deleteDomain($order);
expect((int)$db->query('SELECT COUNT(*) FROM ' . plexZonesTable())->fetchColumn() === 0, 'Delete local zone after provider success.');
expect((int)$db->query('SELECT COUNT(*) FROM ' . plexRecordsTable())->fetchColumn() === 0, 'Delete local records after provider success.');
echo "Hetzner Service checks passed.\n";
