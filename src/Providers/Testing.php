<?php

declare(strict_types=1);

namespace PlexDNS\Providers;

use PDO;

/**
 * Test-only provider backed by Service's non-persistent SQLite database.
 *
 * This adapter does not publish, resolve, validate, or persist DNS data. Its
 * mutation methods are acknowledgements; Service remains the sole writer for
 * zones and records. Never use this provider in production.
 */
final class Testing implements DnsHostingProviderInterface
{
    private const DNSSEC_TABLE = 'plexdns_testing_dnssec';

    public function __construct(private PDO $db)
    {
        if ($db->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'sqlite') {
            throw new \InvalidArgumentException('Testing provider requires an in-memory SQLite database.');
        }

        $databases = $db->query('PRAGMA database_list')->fetchAll(PDO::FETCH_ASSOC);
        $main = array_values(array_filter($databases, static fn (array $database): bool => $database['name'] === 'main'))[0] ?? null;
        $journalMode = $db->query('PRAGMA journal_mode')->fetchColumn();

        if ($main === null || $main['file'] !== '' || $journalMode !== 'memory') {
            throw new \InvalidArgumentException('Testing provider requires an in-memory SQLite database.');
        }

        $db->exec(
            'CREATE TEMP TABLE IF NOT EXISTS ' . self::DNSSEC_TABLE . ' (' .
            'domain_name TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 0)'
        );
    }

    public function createDomain(string $domainName): bool
    {
        $this->normalizeDomain($domainName);
        return true;
    }

    public function listDomains(): array
    {
        $statement = $this->db->query(
            'SELECT zoneId AS id, domain_name AS domain FROM ' . plexZonesTable() . ' ORDER BY domain_name'
        );

        return $statement->fetchAll(PDO::FETCH_ASSOC);
    }

    public function getDomain(string $domainName): array
    {
        $domainName = $this->normalizeDomain($domainName);
        $statement = $this->db->prepare(
            'SELECT zoneId AS Id, domain_name AS Domain FROM ' . plexZonesTable() . ' WHERE domain_name = :domain'
        );
        $statement->execute([':domain' => $domainName]);
        $domain = $statement->fetch(PDO::FETCH_ASSOC);

        if ($domain === false) {
            throw new \RuntimeException("Domain not found: {$domainName}");
        }

        return $domain;
    }

    public function getResponsibleDomain(string $qname): ?string
    {
        $qname = $this->normalizeDomain($qname);
        $matches = [];

        foreach ($this->listDomains() as $domain) {
            $candidate = (string)$domain['domain'];
            if ($qname === $candidate || str_ends_with($qname, '.' . $candidate)) {
                $matches[] = $candidate;
            }
        }

        if (empty($matches)) {
            return null;
        }

        usort($matches, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));
        return $matches[0];
    }

    public function exportDomainAsZonefile(string $domainName): string
    {
        $lines = [];
        foreach ($this->retrieveAllRRsets($domainName) as $rrset) {
            foreach ($rrset['records'] as $record) {
                $value = $rrset['type'] === 'MX'
                    ? $rrset['priority'] . ' ' . $record
                    : $record;
                $lines[] = sprintf(
                    '%s %d IN %s %s',
                    $rrset['subname'],
                    $rrset['ttl'],
                    $rrset['type'],
                    $value
                );
            }
        }

        return $lines === [] ? '' : implode("\n", $lines) . "\n";
    }

    public function deleteDomain(string $domainName): bool
    {
        $statement = $this->db->prepare('DELETE FROM ' . self::DNSSEC_TABLE . ' WHERE domain_name = :domain');
        $statement->execute([':domain' => $this->normalizeDomain($domainName)]);
        return true;
    }

    public function createRRset(string $domainName, array $rrsetData): bool
    {
        $this->requireDomain($domainName);
        $this->validateRRset($rrsetData);
        return true;
    }

    public function createBulkRRsets(string $domainName, array $rrsetDataArray): bool
    {
        foreach ($rrsetDataArray as $rrsetData) {
            $this->createRRset($domainName, $rrsetData);
        }
        return true;
    }

    public function retrieveAllRRsets(string $domainName): array
    {
        return $this->retrieveRRsets($domainName);
    }

    public function retrieveSpecificRRset(string $domainName, string $subname, string $type): array
    {
        return $this->retrieveRRsets($domainName, $subname, $type);
    }

    public function modifyRRset(string $domainName, string $subname, string $type, array $rrsetData): bool
    {
        $this->requireDomain($domainName);
        $this->validateRRset(['subname' => $subname, 'type' => $type] + $rrsetData);
        return true;
    }

    public function modifyBulkRRsets(string $domainName, array $rrsetDataArray): bool
    {
        foreach ($rrsetDataArray as $rrsetData) {
            $this->modifyRRset(
                $domainName,
                (string)($rrsetData['subname'] ?? ''),
                (string)($rrsetData['type'] ?? ''),
                $rrsetData
            );
        }
        return true;
    }

    public function deleteRRset(string $domainName, string $subname, string $type, ?string $value = null): bool
    {
        $this->requireDomain($domainName);
        if ($type === '') {
            throw new \InvalidArgumentException('RRset type cannot be empty.');
        }
        return true;
    }

    public function deleteBulkRRsets(string $domainName, array $rrsetDataArray): bool
    {
        foreach ($rrsetDataArray as $rrsetData) {
            $this->deleteRRset(
                $domainName,
                (string)($rrsetData['subname'] ?? ''),
                (string)($rrsetData['type'] ?? ''),
                (string)($rrsetData['value'] ?? '')
            );
        }
        return true;
    }

    public function enableDNSSEC(string $domainName): array
    {
        $domainName = $this->requireDomain($domainName);
        $statement = $this->db->prepare(
            'INSERT INTO ' . self::DNSSEC_TABLE . ' (domain_name, enabled) VALUES (:domain, 1) ' .
            'ON CONFLICT(domain_name) DO UPDATE SET enabled = 1'
        );
        $statement->execute([':domain' => $domainName]);
        return $this->getDNSSECStatus($domainName);
    }

    public function disableDNSSEC(string $domainName): bool
    {
        $domainName = $this->requireDomain($domainName);
        $statement = $this->db->prepare(
            'INSERT INTO ' . self::DNSSEC_TABLE . ' (domain_name, enabled) VALUES (:domain, 0) ' .
            'ON CONFLICT(domain_name) DO UPDATE SET enabled = 0'
        );
        return $statement->execute([':domain' => $domainName]);
    }

    public function getDNSSECStatus(string $domainName): array
    {
        $domainName = $this->requireDomain($domainName);
        $statement = $this->db->prepare(
            'SELECT enabled FROM ' . self::DNSSEC_TABLE . ' WHERE domain_name = :domain'
        );
        $statement->execute([':domain' => $domainName]);

        return [
            'enabled' => (bool)$statement->fetchColumn(),
            'ds' => [],
            'keys' => [],
        ];
    }

    public function getDSRecords(string $domainName): array
    {
        $this->getDNSSECStatus($domainName);
        return [];
    }

    /** @return array<int,array{subname:string,type:string,ttl:int,priority:int,records:array<int,string>,record_ids:array<int,string>}> */
    private function retrieveRRsets(string $domainName, ?string $subname = null, ?string $type = null): array
    {
        $domainName = $this->requireDomain($domainName);
        $sql = 'SELECT r.recordId, r.host, r.type, r.value, r.ttl, r.priority ' .
            'FROM ' . plexRecordsTable() . ' r JOIN ' . plexZonesTable() . ' z ON z.id = r.domain_id ' .
            'WHERE z.domain_name = :domain';
        $params = [':domain' => $domainName];

        if ($subname !== null) {
            $sql .= ' AND r.host = :subname';
            $params[':subname'] = $subname;
        }
        if ($type !== null) {
            $sql .= ' AND r.type = :type';
            $params[':type'] = strtoupper($type);
        }

        $sql .= ' ORDER BY r.host, r.type, r.priority, r.ttl, r.id';
        $statement = $this->db->prepare($sql);
        $statement->execute($params);
        $rrsets = [];

        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $record) {
            $key = implode("\0", [$record['host'], $record['type'], $record['ttl'], $record['priority']]);
            $rrsets[$key] ??= [
                'subname' => (string)$record['host'],
                'type' => (string)$record['type'],
                'ttl' => (int)$record['ttl'],
                'priority' => (int)$record['priority'],
                'records' => [],
                'record_ids' => [],
            ];
            $rrsets[$key]['records'][] = (string)$record['value'];
            $rrsets[$key]['record_ids'][] = (string)$record['recordId'];
        }

        return array_values($rrsets);
    }

    private function requireDomain(string $domainName): string
    {
        $domainName = $this->normalizeDomain($domainName);
        $statement = $this->db->prepare(
            'SELECT 1 FROM ' . plexZonesTable() . ' WHERE domain_name = :domain'
        );
        $statement->execute([':domain' => $domainName]);

        if ($statement->fetchColumn() === false) {
            throw new \RuntimeException("Domain not found: {$domainName}");
        }

        return $domainName;
    }

    private function normalizeDomain(string $domainName): string
    {
        $domainName = strtolower(rtrim(trim($domainName), '.'));
        if ($domainName === '') {
            throw new \InvalidArgumentException('Domain name cannot be empty.');
        }
        return $domainName;
    }

    /** @param array<string,mixed> $rrsetData */
    private function validateRRset(array $rrsetData): void
    {
        if (empty($rrsetData['type'])) {
            throw new \InvalidArgumentException('RRset type cannot be empty.');
        }
        if (!isset($rrsetData['records']) || !is_array($rrsetData['records'])) {
            throw new \InvalidArgumentException("RRset 'records' must be an array.");
        }
    }
}
