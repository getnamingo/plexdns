<?php

declare(strict_types=1);

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class DigitalOcean implements DnsHostingProviderInterface
{
    private const API_BASE = 'https://api.digitalocean.com/v2/';

    private Client $client;

    public function __construct($config)
    {
        $token = is_array($config) ? trim((string)($config['apikey'] ?? '')) : '';
        if ($token === '') {
            throw new \Exception('API token cannot be empty');
        }

        $this->client = new Client([
            'base_uri' => self::API_BASE,
            'timeout' => 15,
            'connect_timeout' => 5,
            'allow_redirects' => false,
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ],
        ]);
    }

    public function createDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $body = $this->request('POST', 'domains', ['json' => ['name' => $domainName]]);
        $domain = $body['domain'] ?? null;

        if (!is_array($domain)) {
            throw new \RuntimeException('DigitalOcean API did not return the created domain.');
        }

        // Service can persist this as zoneId even though DigitalOcean addresses zones by name.
        $domain['Id'] = (string)($domain['name'] ?? $domainName);

        return $domain;
    }

    public function listDomains()
    {
        return $this->paginate('domains', 'domains');
    }

    public function getDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $body = $this->request('GET', 'domains/' . rawurlencode($domainName));
        $domain = $body['domain'] ?? null;

        if (!is_array($domain)) {
            throw new \RuntimeException('DigitalOcean API did not return domain details.');
        }

        return $domain;
    }

    public function getResponsibleDomain($qname)
    {
        $qname = strtolower(rtrim(trim((string)$qname), '.'));
        if ($qname === '') {
            throw new \InvalidArgumentException('QName cannot be empty.');
        }

        $best = null;
        $bestLength = -1;

        foreach ($this->listDomains() as $domain) {
            if (!is_array($domain) || empty($domain['name'])) {
                continue;
            }

            $name = strtolower(rtrim((string)$domain['name'], '.'));
            if ($qname === $name || str_ends_with($qname, '.' . $name)) {
                if (strlen($name) > $bestLength) {
                    $best = $name;
                    $bestLength = strlen($name);
                }
            }
        }

        return $best;
    }

    public function exportDomainAsZonefile($domainName)
    {
        $domain = $this->getDomain($domainName);
        if (!array_key_exists('zone_file', $domain) || !is_string($domain['zone_file'])) {
            throw new \RuntimeException('DigitalOcean API did not return a zone file.');
        }

        return $domain['zone_file'];
    }

    public function deleteDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $this->request('DELETE', 'domains/' . rawurlencode($domainName));

        return true;
    }

    public function createRRset($domainName, $rrsetData)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $records = $rrsetData['records'] ?? null;

        if (!is_array($records) || $records === []) {
            throw new \InvalidArgumentException("RRset 'records' must be a non-empty array.");
        }

        $ids = [];
        foreach ($records as $value) {
            $payload = $this->recordPayload($rrsetData, (string)$value);
            $body = $this->request(
                'POST',
                'domains/' . rawurlencode($domainName) . '/records',
                ['json' => $payload]
            );
            $record = $body['domain_record'] ?? null;

            if (!is_array($record) || !isset($record['id'])) {
                throw new \RuntimeException('DigitalOcean API did not return a record ID.');
            }

            $ids[] = (string)$record['id'];
        }

        return count($ids) === 1 ? $ids[0] : $ids;
    }

    public function createBulkRRsets($domainName, $rrsetDataArray)
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

    public function retrieveAllRRsets($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        return $this->paginate(
            'domains/' . rawurlencode($domainName) . '/records',
            'domain_records'
        );
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
        if (!is_array($config) || ($config['provider'] ?? null) !== 'DigitalOcean') {
            throw new \InvalidArgumentException('The DNS zone provider does not match DigitalOcean.');
        }

        $rows = [];
        foreach ($this->retrieveAllRRsets($domainName) as $record) {
            if (!is_array($record) || !isset($record['id'], $record['type'], $record['name'], $record['data'])) {
                throw new \RuntimeException('DigitalOcean returned an invalid DNS record.');
            }

            $type = strtoupper((string)$record['type']);
            $host = (string)$record['name'];
            if ($host === '@') {
                $host = '';
            }

            $value = (string)$record['data'];
            if ($type === 'SRV') {
                $value = (int)($record['weight'] ?? 0) . ' '
                    . (int)($record['port'] ?? 0) . ' ' . $value;
            } elseif ($type === 'CAA') {
                $tag = trim((string)($record['tag'] ?? ''));
                if ($tag !== '') {
                    $value = (int)($record['flags'] ?? 0) . ' ' . $tag . ' ' . $value;
                }
            }

            $rows[] = [
                'recordId' => (string)$record['id'],
                'type' => $type,
                'host' => $host,
                'value' => $value,
                'ttl' => (int)($record['ttl'] ?? 1800),
                'priority' => in_array($type, ['MX', 'SRV'], true)
                    ? (int)($record['priority'] ?? 0)
                    : 0,
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
        $name = $this->normalizeSubname((string)$subname);
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
        $domainName = $this->normalizeDomain((string)$domainName);
        $subname = $this->normalizeSubname((string)$subname);
        $type = $this->normalizeType((string)$type);

        $recordId = isset($rrsetData['record_id']) && is_numeric($rrsetData['record_id'])
            ? (int)$rrsetData['record_id']
            : null;

        if ($recordId === null) {
            $lookupValue = $rrsetData['old_value'] ?? ($rrsetData['records'][0] ?? null);
            if ($lookupValue === null) {
                throw new \InvalidArgumentException(
                    "No value provided to locate the DigitalOcean record."
                );
            }

            $recordId = $this->findRecordId(
                $domainName,
                $subname,
                $type,
                (string)$lookupValue
            );
        }

        if (!isset($rrsetData['records'][0])) {
            throw new \InvalidArgumentException("No new value provided in rrsetData['records'][0].");
        }

        $payloadData = $rrsetData;
        $payloadData['subname'] = $subname;
        $payloadData['type'] = $type;
        $payload = $this->recordPayload($payloadData, (string)$rrsetData['records'][0]);

        $body = $this->request(
            'PATCH',
            'domains/' . rawurlencode($domainName) . '/records/' . $recordId,
            ['json' => $payload]
        );

        if (isset($body['domain_record']) && !is_array($body['domain_record'])) {
            throw new \RuntimeException('DigitalOcean API returned an invalid updated record.');
        }

        return true;
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray)
    {
        if (!is_array($rrsetDataArray)) {
            throw new \InvalidArgumentException('rrsetDataArray must be an array.');
        }

        foreach ($rrsetDataArray as $item) {
            if (!is_array($item)) {
                throw new \InvalidArgumentException('Each RRset entry must be an array.');
            }

            $subname = (string)($item['subname'] ?? '');
            $type = (string)($item['type'] ?? '');
            $data = $item['rrsetData'] ?? $item;
            if (!is_array($data)) {
                throw new \InvalidArgumentException('Invalid RRset update data.');
            }

            $this->modifyRRset($domainName, $subname, $type, $data);
        }

        return true;
    }

    public function deleteRRset($domainName, $subname, $type, $value, $persistedRecordId = null)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $subname = $this->normalizeSubname((string)$subname);
        $type = $this->normalizeType((string)$type);

        $recordId = is_numeric($persistedRecordId) ? (int)$persistedRecordId : null;
        if ($recordId === null) {
            $recordId = $this->findRecordId($domainName, $subname, $type, (string)$value);
        }

        $this->request(
            'DELETE',
            'domains/' . rawurlencode($domainName) . '/records/' . $recordId
        );

        return true;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray)
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
                (string)($item['value'] ?? ''),
                $item['record_id'] ?? null
            );
        }

        return true;
    }

    public function enableDNSSEC(string $domainName): array
    {
        throw new \PlexDNS\UnsupportedProviderException(
            'DigitalOcean DNS does not support DNSSEC.'
        );
    }

    public function disableDNSSEC(string $domainName): bool
    {
        throw new \PlexDNS\UnsupportedProviderException(
            'DigitalOcean DNS does not support DNSSEC.'
        );
    }

    public function getDNSSECStatus(string $domainName): array
    {
        throw new \PlexDNS\UnsupportedProviderException(
            'DigitalOcean DNS does not support DNSSEC.'
        );
    }

    public function getDSRecords(string $domainName): array
    {
        throw new \PlexDNS\UnsupportedProviderException(
            'DigitalOcean DNS does not support DNSSEC or DS records.'
        );
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
                        $providerMessage = $decoded['message']
                            ?? $decoded['id']
                            ?? ($decoded['error'] ?? null);
                        if (is_string($providerMessage) && $providerMessage !== '') {
                            $message = $providerMessage;
                        }
                    }
                }
            }

            throw new \RuntimeException('DigitalOcean API request failed: ' . $message, 0, $e);
        }

        $raw = trim((string)$response->getBody());
        if ($raw === '') {
            return [];
        }

        $body = json_decode($raw, true);
        if (!is_array($body)) {
            throw new \RuntimeException('DigitalOcean API returned invalid JSON.');
        }

        return $body;
    }

    private function paginate(string $path, string $key): array
    {
        $all = [];
        $page = 1;

        do {
            $body = $this->request('GET', $path, [
                'query' => ['page' => $page, 'per_page' => 200],
            ]);
            $items = $body[$key] ?? null;

            if (!is_array($items)) {
                throw new \RuntimeException("DigitalOcean API response is missing '{$key}'.");
            }

            foreach ($items as $item) {
                $all[] = $item;
            }

            $page++;
        } while (count($items) === 200);

        return $all;
    }

    private function findRecordId(
        string $domainName,
        string $subname,
        string $type,
        string $value
    ): int {
        $expectedRdata = $this->canonicalRdataFromValue($type, $value);

        foreach ($this->retrieveAllRRsets($domainName) as $record) {
            if (!is_array($record)) {
                continue;
            }
            if (strtoupper((string)($record['type'] ?? '')) !== $type) {
                continue;
            }
            if ((string)($record['name'] ?? '') !== $subname) {
                continue;
            }
            if ($this->canonicalRdataFromRecord($record) !== $expectedRdata) {
                continue;
            }
            if (!isset($record['id']) || !is_numeric($record['id'])) {
                continue;
            }

            return (int)$record['id'];
        }

        throw new \RuntimeException(
            "No DigitalOcean record found with name '{$subname}', type '{$type}' and value '{$value}'."
        );
    }

    private function recordPayload(array $rrsetData, string $value): array
    {
        $type = $this->normalizeType((string)($rrsetData['type'] ?? ''));
        $name = $this->normalizeSubname((string)($rrsetData['subname'] ?? ''));
        $ttl = $this->normalizeTtl($rrsetData['ttl'] ?? 1800);
        $data = trim($value);

        $payload = [
            'type' => $type,
            'name' => $name,
            'data' => $data,
            'ttl' => $ttl,
        ];

        if ($type === 'MX') {
            $priority = $rrsetData['priority'] ?? null;
            if ($priority === null && preg_match('/^(\d+)\s+(.+)$/', $data, $matches)) {
                $priority = (int)$matches[1];
                $payload['data'] = trim($matches[2]);
            }
            if ($priority === null) {
                throw new \InvalidArgumentException('MX records require a priority.');
            }
            $payload['priority'] = (int)$priority;
        }

        if ($type === 'SRV') {
            $priority = $rrsetData['priority'] ?? null;
            $weight = $rrsetData['weight'] ?? null;
            $port = $rrsetData['port'] ?? null;

            if (($priority === null || $weight === null || $port === null)
                && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $data, $matches)) {
                $priority = (int)$matches[1];
                $weight = (int)$matches[2];
                $port = (int)$matches[3];
                $payload['data'] = trim($matches[4]);
            } elseif ($priority !== null && ($weight === null || $port === null)
                && preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $data, $matches)) {
                $weight = (int)$matches[1];
                $port = (int)$matches[2];
                $payload['data'] = trim($matches[3]);
            }

            if ($priority === null || $weight === null || $port === null) {
                throw new \InvalidArgumentException(
                    'SRV records require priority, weight, port and target.'
                );
            }

            $payload['priority'] = (int)$priority;
            $payload['weight'] = (int)$weight;
            $payload['port'] = (int)$port;
        }

        if ($type === 'CAA') {
            $flags = $rrsetData['flags'] ?? 0;
            $tag = trim((string)($rrsetData['tag'] ?? ''));

            if ($tag === ''
                && preg_match('/^(\d+)\s+(issue|issuewild|iodef)\s+(.+)$/i', $data, $matches)) {
                $flags = (int)$matches[1];
                $tag = strtolower($matches[2]);
                $payload['data'] = trim($matches[3]);
            }

            if (!in_array($tag, ['issue', 'issuewild', 'iodef'], true)) {
                throw new \InvalidArgumentException(
                    'CAA records require tag issue, issuewild or iodef.'
                );
            }

            $flags = filter_var($flags, FILTER_VALIDATE_INT);
            if ($flags === false || $flags < 0 || $flags > 255) {
                throw new \InvalidArgumentException('CAA flags must be between 0 and 255.');
            }

            $payload['flags'] = $flags;
            $payload['tag'] = $tag;
        }

        return $payload;
    }

    private function canonicalRdataFromValue(string $type, string $value): string
    {
        $value = trim($value);

        return match ($type) {
            'MX' => preg_match('/^(\d+)\s+(.+)$/', $value, $matches)
                ? (int)$matches[1] . ' ' . trim($matches[2])
                : $value,
            'SRV' => preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $value, $matches)
                ? (int)$matches[1] . ' ' . (int)$matches[2] . ' ' . (int)$matches[3] . ' ' . trim($matches[4])
                : $value,
            'CAA' => preg_match('/^(\d+)\s+(issue|issuewild|iodef)\s+(.+)$/i', $value, $matches)
                ? (int)$matches[1] . ' ' . strtolower($matches[2]) . ' ' . trim($matches[3])
                : $value,
            default => $value,
        };
    }

    private function canonicalRdataFromRecord(array $record): string
    {
        $type = strtoupper((string)($record['type'] ?? ''));
        $data = trim((string)($record['data'] ?? ''));

        return match ($type) {
            'MX' => (int)($record['priority'] ?? 0) . ' ' . $data,
            'SRV' => (int)($record['priority'] ?? 0)
                . ' ' . (int)($record['weight'] ?? 0)
                . ' ' . (int)($record['port'] ?? 0)
                . ' ' . $data,
            'CAA' => (int)($record['flags'] ?? 0)
                . ' ' . strtolower((string)($record['tag'] ?? ''))
                . ' ' . $data,
            default => $data,
        };
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
        $supported = ['A', 'AAAA', 'CAA', 'CNAME', 'MX', 'NS', 'PTR', 'TXT', 'SRV'];

        if (!in_array($type, $supported, true)) {
            throw new \InvalidArgumentException(
                'Unsupported DigitalOcean DNS record type: ' . $type
            );
        }

        return $type;
    }

    private function normalizeTtl($ttl): int
    {
        $ttl = filter_var($ttl, FILTER_VALIDATE_INT);

        if ($ttl === false || $ttl < 30 || $ttl > 2147483647) {
            throw new \InvalidArgumentException(
                'DigitalOcean TTL must be between 30 and 2147483647 seconds.'
            );
        }

        return $ttl;
    }
}
