<?php

namespace Namingo\Cardo\DNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;

class ClouDNS implements DnsHostingProviderInterface {
    private $client;
    private $authId;
    private $authPassword;
    private $baseUrl = 'https://api.cloudns.net/dns/';

    public function __construct($config) {
        $this->authId = $config['cloudns_auth_id'] ?? '';
        $this->authPassword = $config['cloudns_auth_password'] ?? '';

        if (empty($this->authId) || empty($this->authPassword)) {
            throw new Exception("Authentication ID and Password cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl]);
    }

    private function request($endpoint, $params = [], $method = 'POST') {
        $params['auth-id'] = $this->authId;
        $params['auth-password'] = $this->authPassword;

        try {
            $response = $this->client->request($method, $endpoint, [
                'form_params' => $params
            ]);
            $data = json_decode($response->getBody()->getContents(), true);

            if (isset($data['status']) && $data['status'] === 'Failed') {
                throw new Exception("API Error: " . ($data['statusDescription'] ?? 'Unknown error'));
            }

            return $data;
        } catch (RequestException $e) {
            throw new Exception("HTTP Request failed: " . $e->getMessage());
        }
    }

    public function createDomain($domainName, $zoneType = 'master') {
        if (empty($domainName)) {
            throw new Exception("Domain name cannot be empty");
        }

        $response = $this->request('register.json', [
            'domain-name' => $domainName,
            'zone-type' => $zoneType
        ]);
        return json_decode($domainName, true);
    }

    public function listDomains($page = 1, $rowsPerPage = 10) {
        return $this->request('list-zones.json', [
            'page' => $page,
            'rows-per-page' => $rowsPerPage
        ]);
    }

    public function getDomain($domainName) {
        if (empty($domainName)) {
            throw new Exception("Domain name cannot be empty");
        }

        return $this->request('get-zone-info.json', ['domain-name' => $domainName]);
    }
    
    public function getResponsibleDomain($qname) {
        throw new \Exception("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new Exception("Domain name cannot be empty");
        }

        $response = $this->request('delete.json', ['domain-name' => $domainName]);
        return json_decode($domainName, true);
    }

    public function createRRset($domainName, $rrsetData) {
        if (empty($domainName) || !isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records'])) {
            throw new Exception("Missing data for creating RRset");
        }

        $params = [
            'domain-name' => $domainName,
            'host'        => $rrsetData['subname'],
            'record-type' => $rrsetData['type'],
            'record'      => implode("\n", $rrsetData['records']),
            'ttl'         => (int)$rrsetData['ttl'],
        ];

        $this->applyRecordFields(
            $params,
            strtoupper((string)$rrsetData['type']),
            (string)$rrsetData['records'][0],
            $rrsetData
        );

        $response = $this->request('add-record.json', $params);

        if (is_array($response) && isset($response['data']['id'])) {
            return (string)$response['data']['id'];
        }

        return true;
    }
    
    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
    
    public function retrieveAllRRsets($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function sync(\PDO $db, string $domainName): int {
        throw new \Namingo\Cardo\DNS\UnsupportedProviderException('ClouDNS synchronization is not supported.');
    }
    
    public function retrieveSpecificRRset($domainName, $subname, $type) {
        throw new \Exception("Not yet implemented");
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        if (empty($domainName) || empty($type) || empty($rrsetData['ttl']) || empty($rrsetData['records'])) {
            throw new Exception("Missing data for modifying RRset");
        }

        $targetRecord = is_array($rrsetData['records'])
            ? (string) reset($rrsetData['records'])
            : (string) $rrsetData['records'];

        if (isset($rrsetData['old_value']) && $rrsetData['old_value'] !== '') {
            $lookupRecord = (string)$rrsetData['old_value'];   // use OLD value for matching
        } else {
            $lookupRecord = $targetRecord;  // fallback to new value
        }

        $recordId = $rrsetData['record_id'] ?? null;
        $recordId = $recordId === '' ? null : $recordId;
        $records = $recordId === null ? $this->request('records.json', [
            'domain-name' => $domainName,
        ]) : [];

        foreach ($records as $record) {
            if (
                isset($record['type'], $record['host'], $record['record']) &&
                $record['type'] === $type &&
                $record['host'] === $subname &&
                $record['record'] === $lookupRecord
            ) {
                $recordId = $record['id'];
                break;
            }
        }

        if (!$recordId) {
            throw new Exception("Record not found for modification");
        }

        $params = [
            'domain-name' => $domainName,
            'record-id'   => $recordId,
            'record-type' => $type,
            'host'        => $subname,
            'record'      => $targetRecord,
            'ttl'         => (int)$rrsetData['ttl'],
        ];

        $this->applyRecordFields(
            $params,
            strtoupper((string)$type),
            $targetRecord,
            $rrsetData
        );

        $response = $this->request('mod-record.json', $params);

        return json_decode($domainName, true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value, $persistedRecordId = null) {
        if (empty($domainName) || empty($type) || empty($value)) {
            throw new Exception("Missing data for deleting RRset");
        }

        $recordId = $persistedRecordId;
        $recordId = $recordId === '' ? null : $recordId;
        $records = $recordId === null ? $this->request('records.json', [
            'domain-name' => $domainName
        ]) : [];

        foreach ($records as $record) {
                if (
                    $record['host'] === $subname &&
                    $record['type'] === $type &&
                    trim($record['record'], '"') === trim($value, '"')
                ) {
                $recordId = $record['id'];
                break;
            }
        }

        if (!$recordId) {
            throw new Exception("Record not found for deletion");
        }

        $response = $this->request('delete-record.json', [
            'domain-name' => $domainName,
            'record-id' => $recordId
        ]);
        return json_decode($domainName, true);
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    private function applyRecordFields(
        array &$params,
        string $type,
        string $value,
        array $data
    ): void {
        $value = trim($value);

        if ($type === 'MX') {
            $params['priority'] = isset($data['priority']) ? (int)$data['priority'] : 10;
            return;
        }

        if ($type === 'SRV') {
            if (preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $value, $m)) {
                $params['priority'] = (int)$m[1];
                $params['weight'] = (int)$m[2];
                $params['port'] = (int)$m[3];
                $params['record'] = trim($m[4]);
            } else {
                $params['priority'] = (int)($data['priority'] ?? 0);
                $params['weight'] = (int)($data['weight'] ?? 0);
                $params['port'] = (int)($data['port'] ?? 0);
                $params['record'] = $value;
            }
            return;
        }

        if ($type === 'CAA') {
            if (preg_match('/^(\d+)\s+([A-Za-z0-9-]+)\s+(.+)$/', $value, $m)) {
                $params['caa_flag'] = (int)$m[1];
                $params['caa_type'] = strtolower($m[2]);
                $params['caa_value'] = trim($m[3], '"');
            } else {
                $params['caa_flag'] = (int)($data['flags'] ?? 0);
                $params['caa_type'] = strtolower(trim((string)($data['tag'] ?? '')));
                $params['caa_value'] = trim($value, '"');
            }
            $params['record'] = $params['caa_value'];
            return;
        }

        if ($type === 'SSHFP'
            && preg_match('/^(\d+)\s+(\d+)\s+(.+)$/', $value, $m)) {
            $params['algorithm'] = (int)$m[1];
            $params['fp_type'] = (int)$m[2];
            $params['record'] = trim($m[3]);
            return;
        }

        if ($type === 'TLSA'
            && preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(.+)$/', $value, $m)) {
            $params['tlsa_usage'] = (int)$m[1];
            $params['tlsa_selector'] = (int)$m[2];
            $params['tlsa_matching_type'] = (int)$m[3];
            $params['record'] = trim($m[4]);
            return;
        }

        if (in_array($type, ['HTTPS', 'SVCB'], true)
            && preg_match('/^(\d+)\s+(\S+)(?:\s+(.*))?$/', $value, $m)) {
            $params['priority'] = (int)$m[1];
            $params['record'] = $m[2];
            if (isset($m[3]) && trim($m[3]) !== '') {
                $params['parameters'] = trim($m[3]);
            }
        }
    }

    public function enableDNSSEC(string $domainName): array
    {
        if ($domainName === '') {
            throw new Exception("Domain name cannot be empty");
        }

        // Activate DNSSEC for the zone
        $this->request('activate-dnssec.json', [
            'domain-name' => $domainName,
        ]);

        // After activation, return the DS records
        return $this->getDSRecords($domainName);
    }

    public function disableDNSSEC(string $domainName): bool
    {
        if ($domainName === '') {
            throw new Exception("Domain name cannot be empty");
        }

        // Deactivate DNSSEC for the zone
        $this->request('deactivate-dnssec.json', [
            'domain-name' => $domainName,
        ]);

        return true;
    }

    public function getDNSSECStatus(string $domainName): array
    {
        if ($domainName === '') {
            throw new Exception("Domain name cannot be empty");
        }

        // 1) Check if DNSSEC is available at all for this zone
        $available = false;
        $result = $this->request('is-dnssec-available.json', [
            'domain-name' => $domainName,
        ]);

        // is-dnssec-available returns plain 1/0 JSON, so it decodes to int 1 or 0
        if ($result === 1 || $result === '1') {
            $available = true;
        }

        // 2) Check if DNSSEC is actually enabled (i.e. DS records exist)
        $dsRecords = $this->getDSRecords($domainName);

        return [
            'available' => $available,
            'enabled'   => !empty($dsRecords),
            'ds'        => $dsRecords,
        ];
    }

    public function getDSRecords(string $domainName): array
    {
        if ($domainName === '') {
            throw new Exception("Domain name cannot be empty");
        }

        try {
            $data = $this->request('get-dnssec-ds-records.json', [
                'domain-name' => $domainName,
            ]);
        } catch (Exception $e) {
            // DNSSEC not being enabled is a normal state for a new zone.
            $message = $e->getMessage();
            if (
                stripos($message, 'dnssec_not_active') !== false ||
                stripos($message, 'DNSSEC is not active') !== false
            ) {
                return [];
            }
            throw $e;
        }

        $dsRecords = [];

        // ClouDNS: structured records
        if (!empty($data['ds_records']) && is_array($data['ds_records'])) {
            foreach ($data['ds_records'] as $entry) {
                if (
                    isset($entry['key_tag'], $entry['algorithm'], $entry['digest_type'], $entry['digest'])
                ) {
                    $ds = $entry['key_tag'] . ' ' .
                          $entry['algorithm'] . ' ' .
                          $entry['digest_type'] . ' ' .
                          $entry['digest'];

                    $dsRecords[] = $ds;
                }
            }
        }

        // ClouDNS: fallback full DS lines
        if (!empty($data['ds']) && is_array($data['ds'])) {
            foreach ($data['ds'] as $line) {
                if (!is_string($line)) {
                    continue;
                }

                $parts = preg_split('/\s+/', trim($line));
                if (count($parts) >= 4) {
                    $dsRecords[] = implode(' ', array_slice($parts, -4));
                }
            }
        }

        $dsRecords = array_values(array_unique($dsRecords));

        return $dsRecords;
    }
}
