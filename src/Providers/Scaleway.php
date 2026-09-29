<?php

declare(strict_types=1);

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class Scaleway implements DnsHostingProviderInterface
{
    private const API_BASE = 'https://api.scaleway.com/domain/v2beta1/';

    private const RECORD_TYPES = [
        'A', 'AAAA', 'CNAME', 'TXT', 'SRV', 'TLSA', 'MX', 'NS', 'PTR', 'CAA',
        'ALIAS', 'LOC', 'SSHFP', 'HINFO', 'RP', 'URI', 'DS', 'NAPTR', 'DNAME',
        'SVCB', 'HTTPS',
    ];

    private const DNSSEC_ALGORITHMS = [
        'rsamd5' => 1,
        'dh' => 2,
        'dsa' => 3,
        'rsasha1' => 5,
        'dsa_nsec3_sha1' => 6,
        'rsasha1_nsec3_sha1' => 7,
        'rsasha256' => 8,
        'rsasha512' => 10,
        'ecc_gost' => 12,
        'ecdsap256sha256' => 13,
        'ecdsap384sha384' => 14,
        'ed25519' => 15,
        'ed448' => 16,
    ];

    private const DNSSEC_DIGEST_TYPES = [
        'sha_1' => 1,
        'sha_256' => 2,
        'gost_r_34_11_94' => 3,
        'sha_384' => 4,
    ];

    private Client $client;
    private string $projectId;
    private ?string $configuredParentDomain;
    private array $parentDomainCache = [];

    public function __construct($config)
    {
        $token = is_array($config) ? trim((string)($config['apikey'] ?? '')) : '';
        $projectId = is_array($config)
            ? trim((string)($config['project_id'] ?? $config['projectid'] ?? ''))
            : '';

        if ($token === '') {
            throw new \InvalidArgumentException('Scaleway secret key cannot be empty.');
        }
        if ($projectId === '') {
            throw new \InvalidArgumentException('Scaleway project_id cannot be empty.');
        }

        $parent = is_array($config)
            ? trim((string)($config['parent_domain'] ?? $config['scaleway_domain'] ?? ''))
            : '';

        $this->projectId = $projectId;
        $this->configuredParentDomain = $parent === '' ? null : $this->normalizeDomain($parent);

        $this->client = new Client([
            'base_uri' => self::API_BASE,
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'headers' => [
                'X-Auth-Token' => $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function createDomain($domainName)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $existing = $this->findZone($dnsZone);

        if ($existing !== null) {
            return $this->zoneWithId($existing);
        }

        $parent = $this->resolveManagedDomain($dnsZone);
        $subdomain = $dnsZone === $parent
            ? ''
            : substr($dnsZone, 0, -strlen('.' . $parent));

        $zone = $this->request('POST', 'dns-zones', [
            'json' => [
                'domain' => $parent,
                'subdomain' => $subdomain,
                'project_id' => $this->projectId,
            ],
        ]);

        if (!isset($zone['domain'], $zone['subdomain'])) {
            throw new \RuntimeException('Scaleway API did not return the created DNS zone.');
        }

        return $this->zoneWithId($zone);
    }

    public function listDomains()
    {
        $zones = $this->listDNSZones();

        return array_map(fn(array $zone): array => $this->zoneWithId($zone), $zones);
    }

    public function getDomain($domainName)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $zone = $this->findZone($dnsZone);

        if ($zone === null) {
            throw new \RuntimeException("Scaleway DNS zone '{$dnsZone}' was not found.");
        }

        return $this->zoneWithId($zone);
    }

    public function getResponsibleDomain($qname)
    {
        $qname = strtolower(rtrim(trim((string)$qname), '.'));
        if ($qname === '') {
            throw new \InvalidArgumentException('QName cannot be empty.');
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->listDNSZones() as $zone) {
            $zoneName = $this->zoneName($zone);
            if ($qname === $zoneName || str_ends_with($qname, '.' . $zoneName)) {
                if (strlen($zoneName) > $bestLength) {
                    $best = $zoneName;
                    $bestLength = strlen($zoneName);
                }
            }
        }

        return $best;
    }

    public function exportDomainAsZonefile($domainName)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);

        return $this->requestRaw('GET', 'dns-zones/' . rawurlencode($dnsZone) . '/raw', [
            'query' => ['format' => 'bind'],
            'headers' => ['Accept' => 'text/plain'],
        ]);
    }

    public function deleteDomain($domainName)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $parent = $this->resolveManagedDomain($dnsZone);

        if ($dnsZone === $parent) {
            throw new \PlexDNS\UnsupportedProviderException(
                'Scaleway root DNS zones cannot be deleted independently from their managed domain.'
            );
        }

        $this->request('DELETE', 'dns-zones/' . rawurlencode($dnsZone), [
            'query' => ['project_id' => $this->projectId],
        ]);

        return true;
    }

    public function createRRset($domainName, $rrsetData)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $records = $rrsetData['records'] ?? null;

        if (!is_array($records) || $records === []) {
            throw new \InvalidArgumentException("RRset 'records' must be a non-empty array.");
        }

        $newRecords = [];
        foreach ($records as $value) {
            $newRecords[] = $this->recordPayload($rrsetData, (string)$value);
        }

        $response = $this->applyChanges($dnsZone, [
            ['add' => ['records' => $newRecords]],
        ], true);

        $ids = $this->matchReturnedRecordIds($response['records'] ?? [], $newRecords);
        if (count($ids) !== count($newRecords)) {
            $ids = $this->lookupRecordIds($dnsZone, $newRecords);
        }
        if (count($ids) !== count($newRecords)) {
            throw new \RuntimeException('Scaleway API did not return IDs for all created records.');
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    public function createBulkRRsets($domainName, $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        $records = [];
        foreach ($rrsetDataArray as $rrsetData) {
            if (!is_array($rrsetData)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $values = $rrsetData['records'] ?? null;
            if (!is_array($values) || $values === []) {
                throw new \InvalidArgumentException("RRset 'records' must be a non-empty array.");
            }

            foreach ($values as $value) {
                $records[] = $this->recordPayload($rrsetData, (string)$value);
            }
        }

        if ($records !== []) {
            $this->applyChanges(
                $this->normalizeDomain((string)$domainName),
                [['add' => ['records' => $records]]],
                false
            );
        }

        return true;
    }

    public function retrieveAllRRsets($domainName)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);

        return $this->paginate(
            'dns-zones/' . rawurlencode($dnsZone) . '/records',
            'records',
            ['project_id' => $this->projectId]
        );
    }

    public function sync(PDO $db, string $domainName): int
    {
        $dnsZone = $this->normalizeDomain($domainName);

        $zoneStatement = $db->prepare(
            'SELECT id, config FROM ' . plexZonesTable() . ' WHERE domain_name = :domain'
        );
        $zoneStatement->execute([':domain' => $dnsZone]);
        $zone = $zoneStatement->fetch(PDO::FETCH_ASSOC);

        if ($zone === false) {
            throw new \InvalidArgumentException('Zone not found.');
        }

        $config = json_decode((string)$zone['config'], true);
        if (!is_array($config) || ($config['provider'] ?? null) !== 'Scaleway') {
            throw new \InvalidArgumentException('The DNS zone provider does not match Scaleway.');
        }

        $rows = [];
        foreach ($this->retrieveAllRRsets($dnsZone) as $record) {
            if (!is_array($record) || !isset($record['id'], $record['type'], $record['name'], $record['data'])) {
                throw new \RuntimeException('Scaleway returned an invalid DNS record.');
            }

            $rows[] = [
                'recordId' => (string)$record['id'],
                'type' => strtoupper((string)$record['type']),
                'host' => (string)$record['name'],
                'value' => (string)$record['data'],
                'ttl' => (int)($record['ttl'] ?? 3600),
                'priority' => (int)($record['priority'] ?? 0),
            ];
        }

        $now = date('Y-m-d H:i:s');
        $db->beginTransaction();

        try {
            $lockSql = 'SELECT id FROM ' . plexZonesTable() . ' WHERE id = :id';
            if (in_array($db->getAttribute(PDO::ATTR_DRIVER_NAME), ['mysql', 'pgsql'], true)) {
                $lockSql .= ' FOR UPDATE';
            }

            $lock = $db->prepare($lockSql);
            $lock->execute([':id' => $zone['id']]);
            if ($lock->fetchColumn() === false) {
                throw new \RuntimeException('Zone was removed while it was being synchronized.');
            }

            $delete = $db->prepare(
                'DELETE FROM ' . plexRecordsTable() . ' WHERE domain_id = :domain_id'
            );
            $delete->execute([':domain_id' => $zone['id']]);

            $insert = $db->prepare(
                'INSERT INTO ' . plexRecordsTable()
                . ' (domain_id, recordId, type, host, value, ttl, priority, created_at, updated_at)'
                . ' VALUES (:domain_id, :record_id, :type, :host, :value, :ttl, :priority, :created_at, :updated_at)'
            );

            foreach ($rows as $row) {
                $insert->execute([
                    ':domain_id' => $zone['id'],
                    ':record_id' => $row['recordId'],
                    ':type' => $row['type'],
                    ':host' => $row['host'],
                    ':value' => $row['value'],
                    ':ttl' => $row['ttl'],
                    ':priority' => $row['priority'],
                    ':created_at' => $now,
                    ':updated_at' => $now,
                ]);
            }

            $update = $db->prepare(
                'UPDATE ' . plexZonesTable() . ' SET updated_at = :updated_at WHERE id = :id'
            );
            $update->execute([':updated_at' => $now, ':id' => $zone['id']]);

            $db->commit();
        } catch (\Throwable $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            throw $e;
        }

        return count($rows);
    }

    public function retrieveSpecificRRset($domainName, $subname, $type)
    {
        $name = $this->normalizeRecordName((string)$subname);
        $type = $this->normalizeType((string)$type);

        return array_values(array_filter(
            $this->retrieveAllRRsets($domainName),
            static fn($record): bool =>
                is_array($record)
                && strtoupper((string)($record['type'] ?? '')) === $type
                && (string)($record['name'] ?? '') === $name
        ));
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $name = $this->normalizeRecordName((string)$subname);
        $type = $this->normalizeType((string)$type);
        $recordId = $this->resolveRecordId($dnsZone, $name, $type, $rrsetData);

        if (!isset($rrsetData['records'][0])) {
            throw new \InvalidArgumentException("No new value provided in rrsetData['records'][0].");
        }

        $payloadData = $rrsetData;
        $payloadData['subname'] = $name;
        $payloadData['type'] = $type;
        $record = $this->recordPayload($payloadData, (string)$rrsetData['records'][0]);

        $this->applyChanges($dnsZone, [
            ['set' => ['id' => $recordId, 'records' => [$record]]],
        ], false);

        return true;
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        $dnsZone = $this->normalizeDomain((string)$domainName);
        $changes = [];

        foreach ($rrsetDataArray as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $name = $this->normalizeRecordName((string)($item['subname'] ?? ''));
            $type = $this->normalizeType((string)($item['type'] ?? ''));
            $data = $item['rrsetData'] ?? $item;
            if (!is_array($data) || !isset($data['records'][0])) {
                throw new \InvalidArgumentException('Invalid RRset update data.');
            }

            $recordId = $this->resolveRecordId($dnsZone, $name, $type, $data);
            $data['subname'] = $name;
            $data['type'] = $type;

            $changes[] = [
                'set' => [
                    'id' => $recordId,
                    'records' => [$this->recordPayload($data, (string)$data['records'][0])],
                ],
            ];
        }

        if ($changes !== []) {
            $this->applyChanges($dnsZone, $changes, false);
        }

        return true;
    }

    public function deleteRRset($domainName, $subname, $type, $value, $persistedRecordId = null)
    {
        $dnsZone = $this->normalizeDomain((string)$domainName);
        $name = $this->normalizeRecordName((string)$subname);
        $type = $this->normalizeType((string)$type);

        $recordId = is_string($persistedRecordId) && $persistedRecordId !== ''
            ? $persistedRecordId
            : $this->findRecordId($dnsZone, $name, $type, (string)$value);

        $this->applyChanges($dnsZone, [
            ['delete' => ['id' => $recordId]],
        ], false);

        return true;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        $dnsZone = $this->normalizeDomain((string)$domainName);
        $changes = [];

        foreach ($rrsetDataArray as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $name = $this->normalizeRecordName((string)($item['subname'] ?? ''));
            $type = $this->normalizeType((string)($item['type'] ?? ''));
            $id = isset($item['record_id']) && is_string($item['record_id']) && $item['record_id'] !== ''
                ? $item['record_id']
                : $this->findRecordId($dnsZone, $name, $type, (string)($item['value'] ?? ''));

            $changes[] = ['delete' => ['id' => $id]];
        }

        if ($changes !== []) {
            $this->applyChanges($dnsZone, $changes, false);
        }

        return true;
    }

    public function enableDNSSEC(string $domainName): array
    {
        $domain = $this->dnssecDomain($domainName);
        $response = $this->request(
            'POST',
            'domains/' . rawurlencode($domain) . '/enable-dnssec',
            ['json' => (object)[]]
        );

        $domainData = $this->unwrapDomain($response);

        return $this->parseDSRecords($domainData);
    }

    public function disableDNSSEC(string $domainName): bool
    {
        $domain = $this->dnssecDomain($domainName);
        $this->request(
            'POST',
            'domains/' . rawurlencode($domain) . '/disable-dnssec',
            ['json' => (object)[]]
        );

        return true;
    }

    public function getDNSSECStatus(string $domainName): array
    {
        $domain = $this->dnssecDomain($domainName);
        $domainData = $this->request('GET', 'domains/' . rawurlencode($domain));
        $dnssec = is_array($domainData['dnssec'] ?? null) ? $domainData['dnssec'] : [];
        $status = (string)($dnssec['status'] ?? $domainData['dnssec_status'] ?? 'feature_status_unknown');
        $ds = $this->parseDSRecords($domainData);

        return [
            'enabled' => in_array($status, ['enabled', 'enabling'], true),
            'status' => $status,
            'ds' => $ds,
            'raw' => $domainData,
        ];
    }

    public function getDSRecords(string $domainName): array
    {
        $domain = $this->dnssecDomain($domainName);
        $domainData = $this->request('GET', 'domains/' . rawurlencode($domain));

        return $this->parseDSRecords($domainData);
    }

    private function applyChanges(string $dnsZone, array $changes, bool $returnAllRecords): array
    {
        return $this->request(
            'PATCH',
            'dns-zones/' . rawurlencode($dnsZone) . '/records',
            [
                'json' => [
                    'changes' => $changes,
                    'return_all_records' => $returnAllRecords,
                    'disallow_new_zone_creation' => true,
                ],
            ]
        );
    }

    private function listDNSZones(?string $dnsZone = null): array
    {
        $query = ['project_id' => $this->projectId];
        if ($dnsZone !== null) {
            $query['dns_zone'] = $dnsZone;
        }

        return $this->paginate('dns-zones', 'dns_zones', $query);
    }

    private function findZone(string $dnsZone): ?array
    {
        foreach ($this->listDNSZones($dnsZone) as $zone) {
            if (is_array($zone) && $this->zoneName($zone) === $dnsZone) {
                return $zone;
            }
        }

        return null;
    }

    private function zoneWithId(array $zone): array
    {
        $zone['Id'] = $this->zoneName($zone);

        return $zone;
    }

    private function zoneName(array $zone): string
    {
        $domain = $this->normalizeDomain((string)($zone['domain'] ?? ''));
        $subdomain = strtolower(rtrim(trim((string)($zone['subdomain'] ?? '')), '.'));

        return $subdomain === '' ? $domain : $subdomain . '.' . $domain;
    }

    private function resolveManagedDomain(string $dnsZone): string
    {
        if (isset($this->parentDomainCache[$dnsZone])) {
            return $this->parentDomainCache[$dnsZone];
        }

        if ($this->configuredParentDomain !== null) {
            $parent = $this->configuredParentDomain;
            if ($dnsZone !== $parent && !str_ends_with($dnsZone, '.' . $parent)) {
                throw new \InvalidArgumentException(
                    "DNS zone '{$dnsZone}' is not inside configured Scaleway domain '{$parent}'."
                );
            }

            return $this->parentDomainCache[$dnsZone] = $parent;
        }

        $domains = $this->paginate('domains', 'domains', ['project_id' => $this->projectId]);
        $best = null;
        $bestLength = -1;

        foreach ($domains as $domain) {
            if (!is_array($domain) || empty($domain['domain'])) {
                continue;
            }

            $candidate = $this->normalizeDomain((string)$domain['domain']);
            if ($dnsZone === $candidate || str_ends_with($dnsZone, '.' . $candidate)) {
                if (strlen($candidate) > $bestLength) {
                    $best = $candidate;
                    $bestLength = strlen($candidate);
                }
            }
        }

        if ($best === null) {
            throw new \RuntimeException(
                "No Scaleway managed domain contains DNS zone '{$dnsZone}'. "
                . 'Register/manage the domain in Scaleway first or set parent_domain.'
            );
        }

        return $this->parentDomainCache[$dnsZone] = $best;
    }

    private function dnssecDomain(string $domainName): string
    {
        $dnsZone = $this->normalizeDomain($domainName);
        $domain = $this->resolveManagedDomain($dnsZone);

        if ($dnsZone !== $domain) {
            throw new \PlexDNS\UnsupportedProviderException(
                'Scaleway DNSSEC is managed at the parent domain level, not per sub-zone.'
            );
        }

        return $domain;
    }

    private function recordPayload(array $rrsetData, string $value): array
    {
        $type = $this->normalizeType((string)($rrsetData['type'] ?? ''));
        $name = $this->normalizeRecordName((string)($rrsetData['subname'] ?? ''));
        $ttl = $this->normalizeTtl($rrsetData['ttl'] ?? 3600);
        $data = trim($value);
        $priority = isset($rrsetData['priority']) ? (int)$rrsetData['priority'] : 0;

        if ($type === 'MX' && preg_match('/^(\d+)\s+(.+)$/', $data, $matches)) {
            if (!isset($rrsetData['priority'])) {
                $priority = (int)$matches[1];
            }
            $data = trim($matches[2]);
        }

        if ($type === 'SRV') {
            $weight = $rrsetData['weight'] ?? null;
            $port = $rrsetData['port'] ?? null;

            if ($weight !== null && $port !== null) {
                $data = (int)$weight . ' ' . (int)$port . ' ' . $data;
            } elseif (preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $data, $matches)) {
                if (!isset($rrsetData['priority'])) {
                    $priority = (int)$matches[1];
                }
                $data = (int)$matches[2] . ' ' . (int)$matches[3] . ' ' . trim($matches[4]);
            } elseif (!preg_match('/^\d+\s+\d+\s+\S+/', $data)) {
                throw new \InvalidArgumentException(
                    'SRV records require weight, port and target in data or separate weight/port fields.'
                );
            }
        }

        $payload = [
            'data' => $data,
            'name' => $name,
            'priority' => $priority,
            'ttl' => $ttl,
            'type' => $type,
        ];

        foreach (['comment', 'geo_ip_config', 'http_service_config', 'weighted_config', 'view_config'] as $field) {
            if (array_key_exists($field, $rrsetData)) {
                $payload[$field] = $rrsetData[$field];
            }
        }

        return $payload;
    }

    private function resolveRecordId(string $dnsZone, string $name, string $type, array $rrsetData): string
    {
        $recordId = isset($rrsetData['record_id']) ? trim((string)$rrsetData['record_id']) : '';
        if ($recordId !== '') {
            return $recordId;
        }

        $value = $rrsetData['old_value'] ?? null;
        if ($value !== null) {
            return $this->findRecordId($dnsZone, $name, $type, (string)$value);
        }

        $matches = $this->retrieveSpecificRRset($dnsZone, $name, $type);
        if (count($matches) === 1 && !empty($matches[0]['id'])) {
            return (string)$matches[0]['id'];
        }

        throw new \RuntimeException(
            'Scaleway record ID is missing and the record cannot be identified unambiguously.'
        );
    }

    private function findRecordId(string $dnsZone, string $name, string $type, string $value): string
    {
        $expected = $this->comparableData($type, $value);
        $candidates = [];

        foreach ($this->retrieveSpecificRRset($dnsZone, $name, $type) as $record) {
            if (!is_array($record) || empty($record['id'])) {
                continue;
            }

            $candidates[] = $record;
            if ($this->comparableData($type, (string)($record['data'] ?? '')) === $expected) {
                return (string)$record['id'];
            }
        }

        if (count($candidates) === 1) {
            return (string)$candidates[0]['id'];
        }

        throw new \RuntimeException(
            "No unique Scaleway record found with name '{$name}', type '{$type}' and value '{$value}'."
        );
    }

    private function lookupRecordIds(string $dnsZone, array $records): array
    {
        $all = $this->retrieveAllRRsets($dnsZone);
        return $this->matchReturnedRecordIds($all, $records);
    }

    private function matchReturnedRecordIds(array $returned, array $wanted): array
    {
        $ids = [];
        $used = [];

        foreach ($wanted as $record) {
            foreach ($returned as $index => $candidate) {
                if (isset($used[$index]) || !is_array($candidate) || empty($candidate['id'])) {
                    continue;
                }

                if ($this->recordMatches($candidate, $record)) {
                    $ids[] = (string)$candidate['id'];
                    $used[$index] = true;
                    break;
                }
            }
        }

        return $ids;
    }

    private function recordMatches(array $candidate, array $wanted): bool
    {
        return strtoupper((string)($candidate['type'] ?? '')) === $wanted['type']
            && (string)($candidate['name'] ?? '') === $wanted['name']
            && $this->comparableData($wanted['type'], (string)($candidate['data'] ?? ''))
                === $this->comparableData($wanted['type'], (string)$wanted['data'])
            && (int)($candidate['priority'] ?? 0) === (int)($wanted['priority'] ?? 0);
    }

    private function comparableData(string $type, string $value): string
    {
        $value = trim($value);

        if ($type === 'TXT') {
            return trim($value, '"');
        }

        if (in_array($type, ['CNAME', 'NS', 'PTR', 'DNAME'], true)) {
            return strtolower(rtrim($value, '.'));
        }

        if ($type === 'MX' && preg_match('/^\d+\s+(.+)$/', $value, $matches)) {
            return strtolower(rtrim(trim($matches[1]), '.'));
        }

        return $value;
    }

    private function parseDSRecords(array $domainData): array
    {
        $dnssec = is_array($domainData['dnssec'] ?? null) ? $domainData['dnssec'] : [];
        $records = $dnssec['ds_records'] ?? $domainData['ds_records'] ?? [];
        if (!is_array($records)) {
            return [];
        }

        $out = [];
        foreach ($records as $record) {
            if (!is_array($record) || !isset($record['key_id'], $record['algorithm'])) {
                continue;
            }

            $digest = is_array($record['digest'] ?? null) ? $record['digest'] : [];
            $algorithm = self::DNSSEC_ALGORITHMS[(string)$record['algorithm']] ?? null;
            $digestType = self::DNSSEC_DIGEST_TYPES[(string)($digest['type'] ?? '')] ?? null;
            $digestValue = (string)($digest['digest'] ?? '');

            if ($algorithm === null || $digestType === null || $digestValue === '') {
                continue;
            }

            $out[] = [
                'key_tag' => (int)$record['key_id'],
                'algorithm' => $algorithm,
                'digest_type' => $digestType,
                'digest' => $digestValue,
            ];
        }

        return $out;
    }

    private function unwrapDomain(array $response): array
    {
        return is_array($response['domain'] ?? null) ? $response['domain'] : $response;
    }

    private function paginate(string $path, string $key, array $query = []): array
    {
        $all = [];
        $page = 1;
        $pageSize = 100;

        do {
            $body = $this->request('GET', $path, [
                'query' => $query + ['page' => $page, 'page_size' => $pageSize],
            ]);
            $items = $body[$key] ?? null;

            if (!is_array($items)) {
                throw new \RuntimeException("Scaleway API response is missing '{$key}'.");
            }

            foreach ($items as $item) {
                if (is_array($item)) {
                    $all[] = $item;
                }
            }

            $total = isset($body['total_count']) ? (int)$body['total_count'] : null;
            $page++;
        } while (($total !== null && count($all) < $total) || ($total === null && count($items) === $pageSize));

        return $all;
    }

    private function requestRaw(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            throw new \RuntimeException('Scaleway API request failed: ' . $e->getMessage(), 0, $e);
        }

        return (string)$response->getBody();
    }

    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            $message = $e->getMessage();
            $response = $e->getResponse();

            if ($response !== null) {
                $raw = trim((string)$response->getBody());
                if ($raw !== '') {
                    $decoded = json_decode($raw, true);
                    if (is_array($decoded)) {
                        foreach (['message', 'error', 'details'] as $field) {
                            if (isset($decoded[$field]) && is_string($decoded[$field]) && $decoded[$field] !== '') {
                                $message = $decoded[$field];
                                break;
                            }
                        }
                    }
                }
            }

            throw new \RuntimeException('Scaleway API request failed: ' . $message, 0, $e);
        }

        $raw = trim((string)$response->getBody());
        if ($raw === '') {
            return [];
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \RuntimeException('Scaleway API returned invalid JSON.');
        }

        return $body;
    }

    private function normalizeDomain(string $domainName): string
    {
        $domainName = strtolower(rtrim(trim($domainName), '.'));

        if ($domainName === ''
            || strlen($domainName) > 253
            || !filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('A valid ASCII/punycode domain name is required.');
        }

        return $domainName;
    }

    private function normalizeRecordName(string $name): string
    {
        $name = rtrim(trim($name), '.');

        return $name === '@' ? '' : $name;
    }

    private function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new \InvalidArgumentException('Unsupported Scaleway DNS record type: ' . $type);
        }

        return $type;
    }

    private function normalizeTtl($ttl): int
    {
        $ttl = filter_var($ttl, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 1 || $ttl > 4294967295) {
            throw new \InvalidArgumentException('Scaleway TTL must be between 1 and 4294967295 seconds.');
        }

        return $ttl;
    }
}
