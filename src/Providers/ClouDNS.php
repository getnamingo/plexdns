<?php

namespace PlexDNS\Providers;

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

        if (strtoupper($rrsetData['type']) === 'MX') {
            $params['priority'] = isset($rrsetData['priority'])
                ? (int)$rrsetData['priority']
                : 10; // sensible default
        }

        $response = $this->request('add-record.json', $params);

        return json_decode($domainName, true);
    }
    
    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
    
    public function retrieveAllRRsets($domainName) {
        throw new \Exception("Not yet implemented");
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

        // Fetch all records for the domain
        $records = $this->request('records.json', [
            'domain-name' => $domainName,
        ]);

        $recordId = null;
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

        if (strtoupper($type) === 'MX') {
            $params['priority'] = isset($rrsetData['priority'])
                ? (int)$rrsetData['priority']
                : 10; // default
        }

        $response = $this->request('mod-record.json', $params);

        return json_decode($domainName, true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        if (empty($domainName) || empty($type) || empty($value)) {
            throw new Exception("Missing data for deleting RRset");
        }

        // Fetch all records for the domain
        $records = $this->request('records.json', [
            'domain-name' => $domainName
        ]);

        $recordId = null;
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
}