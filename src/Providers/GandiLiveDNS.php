<?php

declare(strict_types=1);

namespace Namingo\Cardo\DNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class GandiLiveDNS implements DnsHostingProviderInterface
{
    private const API_BASE = 'https://api.gandi.net/v5/';

    private const RECORD_TYPES = [
        'A', 'AAAA', 'ALIAS', 'CAA', 'CDS', 'CNAME', 'DNAME', 'DS', 'HTTPS',
        'KEY', 'LOC', 'MX', 'NAPTR', 'NS', 'OPENPGPKEY', 'PTR', 'RP', 'SOA',
        'SPF', 'SRV', 'SSHFP', 'SVCB', 'TLSA', 'TXT', 'WKS',
    ];

    private Client $client;
    private ?string $sharingId;

    public function __construct($config)
    {
        $token = is_array($config) ? trim((string)($config['apikey'] ?? '')) : '';
        if ($token === '') {
            throw new \InvalidArgumentException('Gandi LiveDNS token cannot be empty.');
        }

        $scheme = is_array($config)
            ? trim((string)($config['auth_scheme'] ?? $config['gandi_auth_scheme'] ?? 'Bearer'))
            : 'Bearer';

        if (strcasecmp($scheme, 'Bearer') === 0) {
            $scheme = 'Bearer';
        } elseif (strcasecmp($scheme, 'Apikey') === 0) {
            $scheme = 'Apikey';
        } else {
            throw new \InvalidArgumentException(
                'Gandi auth_scheme must be Bearer or Apikey.'
            );
        }

        $sharingId = is_array($config)
            ? trim((string)($config['sharing_id'] ?? $config['gandi_sharing_id'] ?? ''))
            : '';

        $this->sharingId = $sharingId === '' ? null : $sharingId;
        $this->client = new Client([
            'base_uri' => self::API_BASE,
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'headers' => [
                'Authorization' => $scheme . ' ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function createDomain(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);
        $existing = $this->getDomainOrNull($domainName);

        if ($existing !== null) {
            return $this->domainWithId($existing);
        }

        $this->request('POST', 'livedns/domains', [
            'query' => $this->sharingQuery(),
            'json' => ['fqdn' => $domainName],
        ]);

        $domain = $this->getDomain($domainName);

        return $this->domainWithId($domain);
    }

    public function listDomains()
    {
        $domains = [];
        $page = 1;
        $perPage = 100;

        do {
            $query = $this->sharingQuery() + [
                'page' => $page,
                'per_page' => $perPage,
            ];
            $items = $this->request('GET', 'livedns/domains', ['query' => $query]);

            foreach ($items as $item) {
                if (is_array($item) && isset($item['fqdn'])) {
                    $domains[] = $this->domainWithId($item);
                }
            }

            $page++;
        } while (count($items) === $perPage);

        return $domains;
    }

    public function getDomain(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);
        $domain = $this->request('GET', 'livedns/domains/' . rawurlencode($domainName));

        if (!isset($domain['fqdn'])) {
            throw new \RuntimeException('Gandi LiveDNS did not return domain details.');
        }

        return $this->domainWithId($domain);
    }

    public function getResponsibleDomain(string $qname)
    {
        $qname = strtolower(rtrim(trim($qname), '.'));
        if ($qname === '') {
            throw new \InvalidArgumentException('QName cannot be empty.');
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->listDomains() as $domain) {
            $name = strtolower(rtrim((string)($domain['fqdn'] ?? ''), '.'));
            if ($name === '') {
                continue;
            }

            if ($qname === $name || str_ends_with($qname, '.' . $name)) {
                if (strlen($name) > $bestLength) {
                    $best = $name;
                    $bestLength = strlen($name);
                }
            }
        }

        return $best;
    }

    public function exportDomainAsZonefile(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);

        return $this->requestRaw(
            'GET',
            'livedns/domains/' . rawurlencode($domainName) . '/records',
            ['headers' => ['Accept' => 'text/plain']]
        );
    }

    public function deleteDomain(string $domainName)
    {
        $this->normalizeDomain($domainName);

        throw new \Namingo\Cardo\DNS\UnsupportedProviderException(
            'Gandi LiveDNS does not expose an API operation to remove a domain from LiveDNS.'
        );
    }

    public function createRRset(string $domainName, array $rrsetData)
    {
        $domainName = $this->normalizeDomain($domainName);
        $name = $this->normalizeSubname((string)($rrsetData['subname'] ?? ''));
        $type = $this->normalizeType((string)($rrsetData['type'] ?? ''));
        $records = $rrsetData['records'] ?? null;

        if (!is_array($records) || $records === []) {
            throw new \InvalidArgumentException("RRset 'records' must be a non-empty array.");
        }

        $values = $this->formatValues($type, $records, $rrsetData);
        $existing = $this->getRRsetOrNull($domainName, $name, $type);
        $ttl = $this->normalizeTtl(
            $rrsetData['ttl'] ?? ($existing['rrset_ttl'] ?? 10800)
        );

        if ($existing === null) {
            $this->request(
                'POST',
                'livedns/domains/' . rawurlencode($domainName) . '/records',
                [
                    'json' => [
                        'rrset_name' => $name,
                        'rrset_type' => $type,
                        'rrset_values' => $values,
                        'rrset_ttl' => $ttl,
                    ],
                ]
            );

            return true;
        }

        $currentValues = is_array($existing['rrset_values'] ?? null)
            ? array_map('strval', $existing['rrset_values'])
            : [];
        $toAdd = array_values(array_diff($values, $currentValues));

        if ($toAdd !== [] || (int)($existing['rrset_ttl'] ?? 0) !== $ttl) {
            $this->request(
                'PATCH',
                $this->rrsetPath($domainName, $name, $type),
                [
                    'json' => [
                        'add_rrset_values' => $toAdd,
                        'rrset_ttl' => $ttl,
                    ],
                ]
            );
        }

        return true;
    }

    public function createBulkRRsets(string $domainName, array $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        foreach ($rrsetDataArray as $rrsetData) {
            if (!is_array($rrsetData)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }
            $this->createRRset($domainName, $rrsetData);
        }

        return true;
    }

    public function retrieveAllRRsets(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);
        $records = [];
        $page = 1;
        $perPage = 100;

        do {
            $items = $this->request(
                'GET',
                'livedns/domains/' . rawurlencode($domainName) . '/records',
                ['query' => ['page' => $page, 'per_page' => $perPage]]
            );

            foreach ($items as $item) {
                if (is_array($item)) {
                    $records[] = $item;
                }
            }

            $page++;
        } while (count($items) === $perPage);

        return $records;
    }

    public function sync(PDO $db, string $domainName): int
    {
        $domainName = $this->normalizeDomain($domainName);

        $zoneStatement = $db->prepare(
            'SELECT id, config FROM ' . plexZonesTable() . ' WHERE domain_name = :domain'
        );
        $zoneStatement->execute([':domain' => $domainName]);
        $zone = $zoneStatement->fetch(PDO::FETCH_ASSOC);

        if ($zone === false) {
            throw new \InvalidArgumentException('Zone not found.');
        }

        $config = json_decode((string)$zone['config'], true);
        if (!is_array($config) || ($config['provider'] ?? null) !== 'GandiLiveDNS') {
            throw new \InvalidArgumentException(
                'The DNS zone provider does not match GandiLiveDNS.'
            );
        }

        $rows = [];
        foreach ($this->retrieveAllRRsets($domainName) as $rrset) {
            if (!isset($rrset['rrset_name'], $rrset['rrset_type'], $rrset['rrset_values'])) {
                throw new \RuntimeException('Gandi LiveDNS returned an invalid RRset.');
            }

            $type = strtoupper((string)$rrset['rrset_type']);
            $host = (string)$rrset['rrset_name'];
            $ttl = (int)($rrset['rrset_ttl'] ?? 10800);
            $values = is_array($rrset['rrset_values']) ? $rrset['rrset_values'] : [];

            foreach ($values as $rawValue) {
                [$value, $priority] = $this->toLocalValue($type, (string)$rawValue);

                $rows[] = [
                    'recordId' => null,
                    'type' => $type,
                    'host' => $host,
                    'value' => $value,
                    'ttl' => $ttl,
                    'priority' => $priority,
                ];
            }
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
                throw new \RuntimeException(
                    'Zone was removed while it was being synchronized.'
                );
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

    public function retrieveSpecificRRset(
        string $domainName,
        string $subname,
        string $type
    ) {
        $domainName = $this->normalizeDomain($domainName);
        $name = $this->normalizeSubname($subname);
        $type = $this->normalizeType($type);
        $record = $this->getRRsetOrNull($domainName, $name, $type);

        return $record === null ? [] : [$record];
    }

    public function modifyRRset(
        string $domainName,
        string $subname,
        string $type,
        array $rrsetData
    ) {
        $domainName = $this->normalizeDomain($domainName);
        $name = $this->normalizeSubname($subname);
        $type = $this->normalizeType($type);
        $records = $rrsetData['records'] ?? null;

        if (!is_array($records)) {
            throw new \InvalidArgumentException("RRset 'records' must be an array.");
        }

        if ($records === []) {
            $this->request('DELETE', $this->rrsetPath($domainName, $name, $type));
            return true;
        }

        $ttl = $this->normalizeTtl($rrsetData['ttl'] ?? 10800);
        $newValues = $this->formatValues($type, $records, $rrsetData);
        $oldValue = isset($rrsetData['old_value'])
            ? trim((string)$rrsetData['old_value'])
            : '';

        if ($oldValue !== '') {
            $oldValue = $this->formatExistingValue($type, $oldValue);
            $this->request(
                'PATCH',
                $this->rrsetPath($domainName, $name, $type),
                [
                    'json' => [
                        'add_rrset_values' => $newValues,
                        'remove_rrset_values' => [$oldValue],
                        'rrset_ttl' => $ttl,
                    ],
                ]
            );

            return true;
        }

        $this->request(
            'PUT',
            $this->rrsetPath($domainName, $name, $type),
            [
                'json' => [
                    'rrset_values' => $newValues,
                    'rrset_ttl' => $ttl,
                ],
            ]
        );

        return true;
    }

    public function modifyBulkRRsets(string $domainName, array $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        foreach ($rrsetDataArray as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $name = (string)($item['subname'] ?? '');
            $type = (string)($item['type'] ?? '');
            $data = $item['rrsetData'] ?? $item;

            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid RRset update data.');
            }

            $this->modifyRRset($domainName, $name, $type, $data);
        }

        return true;
    }

    public function deleteRRset(
        string $domainName,
        string $subname,
        string $type,
        string $value
    ) {
        $domainName = $this->normalizeDomain($domainName);
        $name = $this->normalizeSubname($subname);
        $type = $this->normalizeType($type);
        $value = trim($value);

        if ($value === '') {
            $this->request('DELETE', $this->rrsetPath($domainName, $name, $type));
            return true;
        }

        $this->request(
            'PATCH',
            $this->rrsetPath($domainName, $name, $type),
            [
                'json' => [
                    'remove_rrset_values' => [
                        $this->formatExistingValue($type, $value),
                    ],
                ],
            ]
        );

        return true;
    }

    public function deleteBulkRRsets(string $domainName, array $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        foreach ($rrsetDataArray as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $this->deleteRRset(
                $domainName,
                (string)($item['subname'] ?? ''),
                (string)($item['type'] ?? ''),
                (string)($item['value'] ?? '')
            );
        }

        return true;
    }

    public function enableDNSSEC(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);
        $state = $this->getManagedDnssecState($domainName);

        if (!in_array($state['state'] ?? '', ['active', 'activating'], true)) {
            $this->request(
                'POST',
                'domain/domains/' . rawurlencode($domainName) . '/livedns/dnssec',
                ['query' => $this->sharingQuery()]
            );
        }

        return $this->getDSRecords($domainName);
    }

    public function disableDNSSEC(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);

        $this->request(
            'DELETE',
            'domain/domains/' . rawurlencode($domainName) . '/livedns/dnssec',
            ['query' => $this->sharingQuery()]
        );

        return true;
    }

    public function getDNSSECStatus(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);
        $state = $this->getManagedDnssecState($domainName);
        $keys = $this->listDnssecKeys($domainName);
        $ds = $this->parseDSRecords($keys);
        $status = (string)($state['state'] ?? 'inactive');

        return [
            'enabled' => in_array($status, ['active', 'activating'], true),
            'status' => $status,
            'ds' => $ds,
            'keys' => $keys,
            'raw' => $state,
        ];
    }

    public function getDSRecords(string $domainName)
    {
        $domainName = $this->normalizeDomain($domainName);

        return $this->parseDSRecords($this->listDnssecKeys($domainName));
    }

    private function getManagedDnssecState(string $domainName): array
    {
        return $this->request(
            'GET',
            'domain/domains/' . rawurlencode($domainName) . '/livedns/dnssec'
        );
    }

    private function listDnssecKeys(string $domainName): array
    {
        $keys = $this->request(
            'GET',
            'livedns/domains/' . rawurlencode($domainName) . '/keys'
        );

        return array_values(array_filter($keys, 'is_array'));
    }

    private function parseDSRecords(array $keys): array
    {
        $records = [];

        foreach ($keys as $key) {
            if (!is_array($key) || !empty($key['deleted'])) {
                continue;
            }

            $ds = trim((string)($key['ds'] ?? ''));
            if ($ds === '') {
                continue;
            }

            if (!preg_match(
                '/(?:^|\\s)DS\\s+(\\d+)\\s+(\\d+)\\s+(\\d+)\\s+([0-9A-Fa-f]+)\\s*$/i',
                $ds,
                $matches
            )) {
                continue;
            }

            $records[] = [
                'key_tag' => (int)$matches[1],
                'algorithm' => (int)$matches[2],
                'digest_type' => (int)$matches[3],
                'digest' => strtolower($matches[4]),
            ];
        }

        return $records;
    }

    private function getDomainOrNull(string $domainName): ?array
    {
        return $this->requestNullable(
            'GET',
            'livedns/domains/' . rawurlencode($domainName)
        );
    }

    private function getRRsetOrNull(
        string $domainName,
        string $name,
        string $type
    ): ?array {
        return $this->requestNullable(
            'GET',
            $this->rrsetPath($domainName, $name, $type)
        );
    }

    private function rrsetPath(string $domainName, string $name, string $type): string
    {
        return 'livedns/domains/' . rawurlencode($domainName)
            . '/records/' . rawurlencode($name)
            . '/' . rawurlencode($type);
    }

    private function formatValues(string $type, array $records, array $rrsetData): array
    {
        $values = [];

        foreach ($records as $value) {
            $values[] = $this->formatValue($type, (string)$value, $rrsetData);
        }

        return array_values(array_unique($values));
    }

    private function formatValue(string $type, string $value, array $rrsetData): string
    {
        $value = trim($value);

        if ($type === 'MX') {
            if (preg_match('/^\\d+\\s+\\S+/', $value)) {
                return $value;
            }
            if (!isset($rrsetData['priority'])) {
                throw new \InvalidArgumentException('MX records require a priority.');
            }

            return (int)$rrsetData['priority'] . ' ' . $value;
        }

        if ($type === 'SRV') {
            if (preg_match('/^\\d+\\s+\\d+\\s+\\d+\\s+\\S+/', $value)) {
                return $value;
            }
            if (!isset($rrsetData['priority'], $rrsetData['weight'], $rrsetData['port'])) {
                throw new \InvalidArgumentException(
                    'SRV records require priority, weight, port and target.'
                );
            }

            return (int)$rrsetData['priority']
                . ' ' . (int)$rrsetData['weight']
                . ' ' . (int)$rrsetData['port']
                . ' ' . $value;
        }

        if ($type === 'CAA') {
            if (preg_match('/^\\d+\\s+(?:issue|issuewild|iodef)\\s+.+$/i', $value)) {
                return $value;
            }

            $tag = strtolower(trim((string)($rrsetData['tag'] ?? '')));
            if (!in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                throw new \InvalidArgumentException(
                    'CAA records require tag issue, issuewild or iodef.'
                );
            }

            $flags = filter_var($rrsetData['flags'] ?? 0, FILTER_VALIDATE_INT);
            if ($flags === false || $flags < 0 || $flags > 255) {
                throw new \InvalidArgumentException(
                    'CAA flags must be between 0 and 255.'
                );
            }

            return $flags . ' ' . $tag . ' ' . $value;
        }

        return $value;
    }

    private function formatExistingValue(string $type, string $value): string
    {
        $value = trim($value);

        if ($type === 'CAA'
            && preg_match('/^(\\d+)\\s+(issue|issuewild|iodef)\\s+(.+)$/i', $value, $matches)) {
            return (int)$matches[1]
                . ' ' . strtolower($matches[2])
                . ' ' . trim($matches[3]);
        }

        if ($type === 'MX'
            && preg_match('/^(\\d+)\\s+(.+)$/', $value, $matches)) {
            return (int)$matches[1] . ' ' . trim($matches[2]);
        }

        if ($type === 'SRV'
            && preg_match('/^(\\d+)\\s+(\\d+)\\s+(\\d+)\\s+(.+)$/', $value, $matches)) {
            return (int)$matches[1]
                . ' ' . (int)$matches[2]
                . ' ' . (int)$matches[3]
                . ' ' . trim($matches[4]);
        }

        return $value;
    }

    private function toLocalValue(string $type, string $rawValue): array
    {
        $rawValue = trim($rawValue);

        if ($type === 'MX'
            && preg_match('/^(\\d+)\\s+(.+)$/', $rawValue, $matches)) {
            return [trim($matches[2]), (int)$matches[1]];
        }

        if ($type === 'SRV'
            && preg_match('/^(\\d+)\\s+(\\d+)\\s+(\\d+)\\s+(.+)$/', $rawValue, $matches)) {
            return [
                (int)$matches[2] . ' ' . (int)$matches[3] . ' ' . trim($matches[4]),
                (int)$matches[1],
            ];
        }

        return [$rawValue, 0];
    }

    private function domainWithId(array $domain): array
    {
        $domain['Id'] = $this->normalizeDomain((string)($domain['fqdn'] ?? ''));

        return $domain;
    }

    private function sharingQuery(): array
    {
        return $this->sharingId === null ? [] : ['sharing_id' => $this->sharingId];
    }

    private function requestNullable(
        string $method,
        string $path,
        array $options = []
    ): ?array {
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            if ($e->getResponse()?->getStatusCode() === 404) {
                return null;
            }

            throw $this->apiException($e);
        }

        return $this->decodeJsonResponse($response);
    }

    private function request(string $method, string $path, array $options = []): array
    {
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            throw $this->apiException($e);
        }

        return $this->decodeJsonResponse($response);
    }

    private function requestRaw(string $method, string $path, array $options = []): string
    {
        try {
            $response = $this->client->request($method, ltrim($path, '/'), $options);
        } catch (RequestException $e) {
            throw $this->apiException($e);
        }

        return (string)$response->getBody();
    }

    private function decodeJsonResponse($response): array
    {
        $raw = trim((string)$response->getBody());
        if ($raw === '') {
            return [];
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \RuntimeException('Gandi LiveDNS API returned invalid JSON.');
        }

        return $body;
    }

    private function apiException(RequestException $e): \RuntimeException
    {
        $message = $e->getMessage();
        $response = $e->getResponse();

        if ($response !== null) {
            $raw = trim((string)$response->getBody());
            if ($raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    foreach (['message', 'cause', 'object'] as $field) {
                        if (isset($decoded[$field])
                            && is_string($decoded[$field])
                            && $decoded[$field] !== '') {
                            $message = $decoded[$field];
                            break;
                        }
                    }
                }
            }
        }

        return new \RuntimeException(
            'Gandi LiveDNS API request failed: ' . $message,
            0,
            $e
        );
    }

    private function normalizeDomain(string $domainName): string
    {
        $domainName = strtolower(rtrim(trim($domainName), '.'));

        if ($domainName === ''
            || strlen($domainName) > 253
            || !filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException(
                'A valid ASCII/punycode domain name is required.'
            );
        }

        return $domainName;
    }

    private function normalizeSubname(string $subname): string
    {
        $subname = rtrim(trim($subname), '.');

        return $subname === '' ? '@' : $subname;
    }

    private function normalizeType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (!in_array($type, self::RECORD_TYPES, true)) {
            throw new \InvalidArgumentException(
                'Unsupported Gandi LiveDNS record type: ' . $type
            );
        }

        return $type;
    }

    private function normalizeTtl($ttl): int
    {
        $ttl = filter_var($ttl, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 300 || $ttl > 2592000) {
            throw new \InvalidArgumentException(
                'Gandi LiveDNS TTL must be between 300 and 2592000 seconds.'
            );
        }

        return $ttl;
    }
}
