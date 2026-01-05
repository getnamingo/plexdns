<?php

declare(strict_types=1);

namespace PlexDNS\Providers;

class Bunny implements DnsHostingProviderInterface
{
    private const API_BASE = 'https://api.bunny.net';

    private string $apiKey;

    /** @var array<string,int> map domain => zoneId */
    private array $zoneIdCache = [];

    private const TYPE_MAP = [
        'A'     => 0,
        'AAAA'  => 1,
        'CNAME' => 2,
        'TXT'   => 3,
        'MX'    => 4,
        'RDR'   => 5,
        'NS'    => 6,
    ];

    public function __construct($config)
    {
        $key = is_array($config) ? (string)($config['apikey'] ?? '') : '';
        if ($key === '') {
            throw new \Exception("API token cannot be empty");
        }
        $this->apiKey = $key;
    }

    /* ---------------------------
     * Domains / Zones
     * ------------------------- */

    public function createDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $resp = $this->request('POST', '/dnszone', ['Domain' => $domainName]);

            if (is_array($resp) && isset($resp['Id']) && is_numeric($resp['Id'])) {
                $this->zoneIdCache[$domainName] = (int)$resp['Id'];
            }

            return $resp;
        } catch (\Throwable $e) {
            throw new \Exception("Error creating domain: " . $e->getMessage());
        }
    }

    public function listDomains()
    {
        try {
            $zones = $this->listAllZones();

            $out = [];
            foreach ($zones as $z) {
                if (!is_array($z)) continue;
                $out[] = [
                    'id'     => $z['Id'] ?? null,
                    'domain' => $z['Domain'] ?? null,
                ];
            }

            return $out;
        } catch (\Throwable $e) {
            throw new \Exception("Error listing domains: " . $e->getMessage());
        }
    }

    public function getDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);
            return $this->request('GET', '/dnszone/' . $zoneId);
        } catch (\Throwable $e) {
            throw new \Exception("Error getting domain: " . $e->getMessage());
        }
    }

    public function getResponsibleDomain($qname)
    {
        $qname = strtolower(rtrim((string)$qname, '.'));
        if ($qname === '') {
            throw new \Exception("QName cannot be empty");
        }

        $zones = $this->listAllZones();
        $best = null;
        $bestLen = -1;

        foreach ($zones as $z) {
            if (!is_array($z) || empty($z['Domain'])) continue;
            $d = strtolower(rtrim((string)$z['Domain'], '.'));
            if ($d === '') continue;

            if ($qname === $d || str_ends_with($qname, '.' . $d)) {
                $len = strlen($d);
                if ($len > $bestLen) {
                    $bestLen = $len;
                    $best = $d;
                }
            }
        }

        if ($best === null) {
            throw new \Exception("No responsible domain found for qname: {$qname}");
        }

        return $best;
    }

    public function exportDomainAsZonefile($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);
            $resp = $this->request('GET', '/dnszone/' . $zoneId . '/export', null, [
                'Accept' => 'text/plain',
            ]);

            if (!is_string($resp)) {
                return is_array($resp) ? (string)($resp['Content'] ?? json_encode($resp, JSON_PRETTY_PRINT)) : (string)$resp;
            }

            return $resp;
        } catch (\Throwable $e) {
            throw new \Exception("Error exporting domain as zonefile: " . $e->getMessage());
        }
    }

    public function deleteDomain($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);
            $this->request('DELETE', '/dnszone/' . $zoneId);
            unset($this->zoneIdCache[$domainName]);
            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error deleting domain: " . $e->getMessage());
        }
    }

    /* ---------------------------
     * RRsets / Records
     * ------------------------- */

    public function createRRset($domainName, $rrsetData)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);

            $typeStr = strtoupper((string)($rrsetData['type'] ?? ''));
            $typeId  = isset($rrsetData['type_id']) ? (int)$rrsetData['type_id'] : $this->mapTypeToId($typeStr);

            $name = (string)($rrsetData['subname'] ?? '');
            $ttl  = isset($rrsetData['ttl']) ? (int)$rrsetData['ttl'] : 300;

            $values = $rrsetData['records'] ?? null;
            if (!is_array($values) || count($values) < 1) {
                throw new \Exception("RRset 'records' must be a non-empty array.");
            }

            $priority = null;
            if (in_array($typeStr, ['MX', 'SRV'], true)) {
                $priority = isset($rrsetData['priority']) ? (int)$rrsetData['priority'] : 10;
            }

            foreach ($values as $val) {
                $payload = [
                    'Type' => $typeId,
                    'Name' => $name,
                    'Value' => (string)$val,
                    'Ttl' => $ttl,
                ];

                if ($priority !== null) {
                    $payload['Priority'] = $priority;
                }

                if (isset($rrsetData['accelerated'])) $payload['Accelerated'] = (bool)$rrsetData['accelerated'];
                if (isset($rrsetData['weight'])) $payload['Weight'] = (int)$rrsetData['weight'];

                $this->request('PUT', '/dnszone/' . $zoneId . '/records', $payload);
            }

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error creating record: " . $e->getMessage());
        }
    }

    public function createBulkRRsets($domainName, $rrsetDataArray)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        if (!is_array($rrsetDataArray)) {
            throw new \Exception("rrsetDataArray must be an array");
        }

        try {
            foreach ($rrsetDataArray as $rrsetData) {
                $this->createRRset($domainName, $rrsetData);
            }
            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error creating bulk records: " . $e->getMessage());
        }
    }

    public function retrieveAllRRsets($domainName)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        try {
            $zone = $this->getDomain($domainName);

            $records = is_array($zone) ? ($zone['Records'] ?? []) : [];

            return is_array($records) ? $records : [];
        } catch (\Throwable $e) {
            throw new \Exception("Error retrieving records: " . $e->getMessage());
        }
    }

    public function retrieveSpecificRRset($domainName, $subname, $type)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $subname = (string)($subname ?? '');
        $typeStr = strtoupper((string)$type);

        try {
            $all = $this->retrieveAllRRsets($domainName);

            $out = [];
            foreach ($all as $r) {
                if (!is_array($r)) continue;

                $rName = (string)($r['Name'] ?? '');
                $rTypeId = $r['Type'] ?? null;

                if ($rName !== $subname) continue;

                $targetTypeId = $this->mapTypeToId($typeStr);
                if ($rTypeId !== null && (int)$rTypeId !== (int)$targetTypeId) continue;

                $out[] = $r;
            }

            return $out;
        } catch (\Throwable $e) {
            throw new \Exception("Error retrieving specific rrset: " . $e->getMessage());
        }
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $subname = (string)($subname ?? '');
        $typeStr = strtoupper((string)$type);

        try {
            $zoneId = $this->resolveZoneId($domainName);

            $lookupData = $rrsetData['old_value'] ?? ($rrsetData['records'][0] ?? null);
            if ($lookupData === null) {
                throw new \Exception("No value provided to locate record. Provide rrsetData['old_value'] or rrsetData['records'][0].");
            }

            $records = $this->retrieveAllRRsets($domainName);

            $targetTypeId = isset($rrsetData['type_id'])
                ? (int)$rrsetData['type_id']
                : $this->mapTypeToId($typeStr);

            $recordId = null;
            $found = null;

            foreach ($records as $r) {
                if (!is_array($r)) continue;

                if ((string)($r['Name'] ?? '') !== $subname) continue;
                if ((int)($r['Type'] ?? -1) !== (int)$targetTypeId) continue;
                if ((string)($r['Value'] ?? '') !== (string)$lookupData) continue;

                $recordId = $r['Id'] ?? null;
                $found = $r;
                break;
            }

            if ($recordId === null) {
                throw new \Exception("No record found with name '{$subname}', type '{$typeStr}' and value '{$lookupData}'");
            }

            $newValue = $rrsetData['records'][0] ?? null;
            if ($newValue === null || $newValue === '') {
                throw new \Exception("No new value provided in rrsetData['records'][0]");
            }

            $payload = [
                'Type'  => $targetTypeId,
                'Name'  => $subname,
                'Value' => (string)$newValue,
                'Ttl'   => isset($rrsetData['ttl']) ? (int)$rrsetData['ttl'] : (int)($found['Ttl'] ?? 300),
            ];

            if (in_array($typeStr, ['MX', 'SRV'], true)) {
                $payload['Priority'] = isset($rrsetData['priority'])
                    ? (int)$rrsetData['priority']
                    : (int)($found['Priority'] ?? 10);
            }

            if (isset($rrsetData['accelerated'])) $payload['Accelerated'] = (bool)$rrsetData['accelerated'];
            if (isset($rrsetData['weight'])) $payload['Weight'] = (int)$rrsetData['weight'];

            $this->request('POST', '/dnszone/' . $zoneId . '/records/' . (int)$recordId, $payload);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error updating record: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        if (!is_array($rrsetDataArray)) {
            throw new \Exception("rrsetDataArray must be an array");
        }

        try {
            foreach ($rrsetDataArray as $item) {
                $subname = (string)($item['subname'] ?? '');
                $type    = (string)($item['type'] ?? '');
                $data    = $item['rrsetData'] ?? $item;

                $this->modifyRRset($domainName, $subname, $type, $data);
            }
            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error updating bulk records: " . $e->getMessage());
        }
    }

    public function deleteRRset($domainName, $subname, $type, $value)
    {
        $domainName = $this->normalizeDomain((string)$domainName);
        $subname = (string)($subname ?? '');
        $typeStr = strtoupper((string)$type);
        $value = (string)$value;

        try {
            $zoneId = $this->resolveZoneId($domainName);

            $records = $this->retrieveAllRRsets($domainName);
            $targetTypeId = $this->mapTypeToId($typeStr);

            $recordId = null;

            foreach ($records as $r) {
                if (!is_array($r)) continue;

                if ((int)($r['Type'] ?? -1) !== (int)$targetTypeId) continue;
                if ((string)($r['Name'] ?? '') !== $subname) continue;

                if ($value !== '' && (string)($r['Value'] ?? '') !== $value) continue;

                $recordId = $r['Id'] ?? null;
                if ($recordId !== null) break;
            }

            if ($recordId === null) {
                throw new \Exception("No record found with name '{$subname}' and type '{$typeStr}'" . ($value !== '' ? " and value '{$value}'" : ""));
            }

            $this->request('DELETE', '/dnszone/' . $zoneId . '/records/' . (int)$recordId);

            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray)
    {
        $domainName = $this->normalizeDomain((string)$domainName);

        if (!is_array($rrsetDataArray)) {
            throw new \Exception("rrsetDataArray must be an array");
        }

        try {
            foreach ($rrsetDataArray as $item) {
                $subname = (string)($item['subname'] ?? '');
                $type    = (string)($item['type'] ?? '');
                $value   = (string)($item['value'] ?? '');

                $this->deleteRRset($domainName, $subname, $type, $value);
            }
            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error deleting bulk records: " . $e->getMessage());
        }
    }

    /* ---------------------------
     * DNSSEC
     * ------------------------- */

    public function enableDNSSEC(string $domainName): array
    {
        $domainName = $this->normalizeDomain($domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);
            $resp = $this->request('POST', '/dnszone/' . $zoneId . '/dnssec');
            return is_array($resp) ? $resp : ['result' => $resp];
        } catch (\Throwable $e) {
            throw new \Exception("Error enabling DNSSEC: " . $e->getMessage());
        }
    }

    public function disableDNSSEC(string $domainName): bool
    {
        $domainName = $this->normalizeDomain($domainName);

        try {
            $zoneId = $this->resolveZoneId($domainName);
            $this->request('DELETE', '/dnszone/' . $zoneId . '/dnssec');
            return true;
        } catch (\Throwable $e) {
            throw new \Exception("Error disabling DNSSEC: " . $e->getMessage());
        }
    }

    public function getDNSSECStatus(string $domainName): array
    {
        $domainName = $this->normalizeDomain($domainName);

        try {
            $zone = $this->getDomain($domainName);

            $enabled = (bool)($zone['DnsSecEnabled'] ?? $zone['DnsSec'] ?? false);

            $ds = $zone['DnsSecRecords'] ?? $zone['DSRecords'] ?? $zone['DnsSecInfo'] ?? null;

            return [
                'enabled' => $enabled,
                'ds'      => $ds,
            ];
        } catch (\Throwable $e) {
            throw new \Exception("Error getting DNSSEC status: " . $e->getMessage());
        }
    }

    public function getDSRecords(string $domainName): array
    {
        $status = $this->getDNSSECStatus($domainName);

        if (empty($status['ds'])) {
            return [];
        }

        return is_array($status['ds']) ? $status['ds'] : [$status['ds']];
    }

    /* ---------------------------
     * Internal helpers
     * ------------------------- */

    private function normalizeDomain(string $domain): string
    {
        $domain = strtolower(trim($domain));
        $domain = rtrim($domain, '.');

        if ($domain === '') {
            throw new \Exception("Domain name cannot be empty");
        }

        return $domain;
    }

    private function mapTypeToId(string $type): int
    {
        if ($type === '') {
            throw new \Exception("Record type cannot be empty");
        }

        if (!isset(self::TYPE_MAP[$type])) {
            throw new \Exception(
                "Unsupported record type '{$type}' for Bunny mapping. " .
                "Either extend TYPE_MAP or pass rrsetData['type_id'] explicitly."
            );
        }

        return self::TYPE_MAP[$type];
    }

    private function resolveZoneId(string $domainName): int
    {
        if (isset($this->zoneIdCache[$domainName])) {
            return $this->zoneIdCache[$domainName];
        }

        $zones = $this->listAllZones();

        foreach ($zones as $z) {
            if (!is_array($z)) continue;
            $d = strtolower(rtrim((string)($z['Domain'] ?? ''), '.'));
            if ($d === $domainName && isset($z['Id']) && is_numeric($z['Id'])) {
                $this->zoneIdCache[$domainName] = (int)$z['Id'];
                return (int)$z['Id'];
            }
        }

        throw new \Exception("Bunny DNS zone not found for domain: {$domainName}");
    }

    /**
     * Bunny lists zones with pagination (Items/HasMoreItems).
     * We fetch all pages defensively.
     */
    private function listAllZones(): array
    {
        $page = 1;
        $perPage = 1000;
        $items = [];

        while (true) {
            $resp = $this->request('GET', '/dnszone?page=' . $page . '&perPage=' . $perPage);

            if (is_array($resp) && array_key_exists('Items', $resp) && is_array($resp['Items'])) {
                $items = array_merge($items, $resp['Items']);
                $hasMore = (bool)($resp['HasMoreItems'] ?? false);
                if (!$hasMore) break;
                $page++;
                continue;
            }

            if (is_array($resp)) {
                $items = $resp;
            }

            break;
        }

        return $items;
    }

    /**
     * Minimal HTTP client around Bunny API.
     *
     * - Uses AccessKey header for auth.
     * - Sends JSON bodies for POST/PUT when $body is not null.
     * - If response is JSON, returns array; otherwise returns string.
     */
    private function request(string $method, string $path, ?array $body = null, array $extraHeaders = [])
    {
        $url = self::API_BASE . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new \Exception("Failed to init curl");
        }

        $headers = [
            'AccessKey: ' . $this->apiKey,
        ];

        if ($body !== null) {
            $payload = json_encode($body, JSON_UNESCAPED_SLASHES);
            if ($payload === false) {
                throw new \Exception("Failed to JSON-encode request body");
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $payload);
            $headers[] = 'Content-Type: application/json';
        }

        foreach ($extraHeaders as $k => $v) {
            if (is_int($k)) $headers[] = (string)$v;
            else $headers[] = $k . ': ' . $v;
        }

        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 30,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new \Exception("HTTP request failed: {$err}");
        }

        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)(curl_getinfo($ch, CURLINFO_CONTENT_TYPE) ?: '');
        curl_close($ch);

        if ($status >= 400) {
            $msg = "HTTP {$status}";

            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $apiMsg = $decoded['Message'] ?? $decoded['error'] ?? null;
                $apiKey = $decoded['ErrorKey'] ?? null;
                if ($apiKey || $apiMsg) {
                    $msg .= " - " . trim(($apiKey ? $apiKey . ': ' : '') . ($apiMsg ?? ''));
                } else {
                    $msg .= " - " . json_encode($decoded);
                }
            } else {
                $msg .= " - " . $raw;
            }

            throw new \Exception($msg);
        }

        $isJson = str_contains(strtolower($contentType), 'application/json')
            || (strlen($raw) > 0 && ($raw[0] === '{' || $raw[0] === '['));

        if ($isJson) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                return $decoded;
            }
        }

        return $raw;
    }
}