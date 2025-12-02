<?php

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Exception;

class AnycastDNS implements DnsHostingProviderInterface {
    private $client;
    private $apiKey;
    private $serverId;

    public function __construct($config) {
        $this->apiKey = $config['apikey'] ?? null;

        if (empty($this->apiKey)) {
            throw new Exception("API key cannot be empty");
        }

        $this->serverId = isset($config['serverid']) ? (int)$config['serverid'] : 0;

        $this->client = new Client([
            'base_uri' => 'https://api.anycastdns.app/',
            'headers' => [
                'Authorization' => 'Bearer ' . $this->apiKey,
                'Accept' => 'application/json',
            ],
        ]);
    }

    private function request($method, $endpoint, $params = []) {
        try {
            $options = [];
            if ($method === 'GET') {
                $options['query'] = $params;
            } else {
                $options['json'] = $params;
            }

            $response = $this->client->request($method, $endpoint, $options);
            return json_decode($response->getBody(), true);
        } catch (RequestException $e) {
            $response = $e->getResponse();
            $message = $response ? $response->getBody()->getContents() : $e->getMessage();
            throw new Exception("HTTP request failed: " . $message);
        }
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new Exception("Domain name cannot be empty");
        }

        $params = [
            'serverid' => $this->serverId,
            'domains'  => [$domainName],
        ];

        return $this->request('POST', 'domains/', $params);
    }

    public function listDomains() {
        throw new \Exception("Not yet implemented");
    }

    public function getDomain($domainName) {
        if (empty($domainName)) {
            throw new Exception("Domain name cannot be empty");
        }

        return $this->request('GET', "domain/{$domainName}");
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

        return $this->request('DELETE', "domains/{$domainName}");
    }

    public function createRRset($domainName, $rrsetData) {
        if (empty($domainName) || !isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records'])) {
            throw new Exception("Missing data for creating RRset");
        }

        $subname = trim((string)$rrsetData['subname']) === '' ? '@' : $rrsetData['subname'];

        $content = is_array($rrsetData['records'])
            ? (string) reset($rrsetData['records'])
            : (string) $rrsetData['records'];

        $params = [
            'type'    => strtoupper($rrsetData['type']),
            'name'    => $subname,
            'content' => $content,
            'ttl'     => (int) $rrsetData['ttl'],
        ];

        if (in_array($params['type'], ['MX', 'SRV'], true) && isset($rrsetData['priority'])) {
            $params['prio'] = (int) $rrsetData['priority'];
        }

        $result = $this->request('POST', "dns/{$domainName}/record", $params);

        if (is_array($result) && isset($result['record_id'])) {
            return (string)$result['record_id'];
        }

        return true;
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

    public function modifyRRset($domainName, $subname, $type, $rrsetData)
    {
        if (empty($domainName) || empty($type) || empty($rrsetData['ttl']) || empty($rrsetData['records'])) {
            throw new Exception("Missing data for modifying RRset");
        }

        $name = trim((string)$subname) === '' ? '@' : $subname;

        $content = is_array($rrsetData['records'])
            ? (string) reset($rrsetData['records'])
            : (string) $rrsetData['records'];

        $lookupContent = (isset($rrsetData['old_value']) && $rrsetData['old_value'] !== '')
            ? (string) $rrsetData['old_value']
            : $content;

        $all = $this->request('GET', "dns/{$domainName}");
        $records = isset($all['records']) ? $all['records'] : $all;

        $recordId = null;

        foreach ($records as $rec) {
            $recName = isset($rec['name']) ? $rec['name'] : '';
            $recNameNorm = ($recName === '' || $recName === '@') ? '@' : $recName;

            if (
                strtoupper($rec['type'] ?? '') === strtoupper($type) &&
                $recNameNorm === $name &&
                isset($rec['content']) &&
                $rec['content'] === $lookupContent
            ) {
                $recordId = $rec['id'] ?? null;
                if ($recordId !== null) {
                    break;
                }
            }
        }

        if ($recordId === null) {
            throw new Exception("No matching record found for {$name}.{$domainName} ({$type})");
        }

        $params = [
            'type'    => strtoupper($type),
            'name'    => $name,
            'content' => $content,
            'ttl'     => (int) $rrsetData['ttl'],
        ];

        if (in_array(strtoupper($type), ['MX', 'SRV'], true) && isset($rrsetData['priority'])) {
            $params['prio'] = (int) $rrsetData['priority'];
        }

        return $this->request('PUT', "dns/{$domainName}/record/{$recordId}", $params);
    }
    
    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value)
    {
        if (empty($domainName) || empty($type) || empty($value)) {
            throw new Exception("Missing data for deleting RRset");
        }

        $name = trim((string)$subname) === '' ? '@' : $subname;

        $all = $this->request('GET', "dns/{$domainName}");
        $records = isset($all['records']) ? $all['records'] : $all;

        $recordId = null;

        foreach ($records as $rec) {

            $recName = isset($rec['name']) ? $rec['name'] : '';
            $recNameNorm = ($recName === '' || $recName === '@') ? '@' : $recName;

            if (
                strtoupper($rec['type'] ?? '') === strtoupper($type) &&
                $recNameNorm === $name &&
                isset($rec['content']) &&
                $rec['content'] === $value
            ) {
                $recordId = $rec['id'] ?? null;
                if ($recordId !== null) {
                    break;
                }
            }
        }

        if ($recordId === null) {
            throw new Exception("Record not found for {$name}.{$domainName} ($type = $value)");
        }

        return $this->request('DELETE', "dns/{$domainName}/record/{$recordId}");
    }
    
    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
}