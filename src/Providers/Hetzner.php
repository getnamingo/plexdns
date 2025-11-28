<?php

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use PDO;

class Hetzner implements DnsHostingProviderInterface {
    private $baseUrl = "https://dns.hetzner.com/api/v1/";
    private $client;
    private $headers;
    private PDO $pdo;

    public function __construct($config, PDO $pdo) {
        $this->pdo = $pdo;
        
        $token = $config['apikey'];
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl]);
        $this->headers = [
            'Auth-API-Token' => $token,
            'Content-Type' => 'application/json',
        ];
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        try {
            $response = $this->client->request('POST', 'zones', [
                'headers' => $this->headers,
                'json'    => ['name' => $domainName],
            ]);

            $body   = json_decode($response->getBody()->getContents(), true);
            $zoneId = $body['zone']['id'] ?? null;

            if ($zoneId === null) {
                throw new \Exception("Hetzner API did not return a zone ID for {$domainName}");
            }

            try {
                saveZoneId($this->pdo, $domainName, $zoneId);
            } catch (\PDOException $e) {
                throw new \Exception("Error saving zoneId: " . $e->getMessage());
            }

            return $body;
        } catch (\Exception $e) {
            throw new \Exception('Request failed: ' . $e->getMessage());
        }
    }

    public function listDomains() {
        throw new \Exception("Not yet implemented");
    }

    public function getDomain($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function getResponsibleDomain($qname) {
        throw new \Exception("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }
        
        try {
            $result = getZoneId($this->pdo, $domainName);
            $zoneId = $result['zoneId'];
        } catch (\PDOException $e) {
            throw new \Exception("Error fetching zoneId: " . $e->getMessage());
        }

        try {
            $response = $this->client->request('DELETE', "zones/{$zoneId}", [
                'headers' => $this->headers,
            ]);

            if ($response->getStatusCode() === 204) {
                return true;
            } else {
                return false;
            }
        } catch (\GuzzleException $e) {
            throw new \exception('Request failed: ' . $e->getMessage());
        }
    }

    public function createRRset($domainName, $rrsetData) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (
            empty($rrsetData['type']) ||
            !isset($rrsetData['subname'], $rrsetData['ttl'], $rrsetData['records']) ||
            empty($rrsetData['records'])
        ) {
            throw new \Exception("Missing data for creating RRset");
        }

        try {
            $result = getZoneId($this->pdo, $domainName);
            $zoneId = $result['zoneId'];
        } catch (\PDOException $e) {
            throw new \Exception("Error fetching zoneId: " . $e->getMessage());
        }

        $type    = strtoupper($rrsetData['type']);
        $ttl     = (int)$rrsetData['ttl'];
        $value   = (string)$rrsetData['records'][0];
        $subname = $rrsetData['subname'];

        $apiName = ($subname === '' || $subname === '@') ? '@' : $subname;

        $payload = [
            'value'   => $value,
            'ttl'     => $ttl,
            'type'    => $type,
            'name'    => $apiName,
            'zone_id' => $zoneId,
        ];

        if (in_array($type, ['MX', 'SRV'], true) && isset($rrsetData['priority'])) {
            $payload['priority'] = (int)$rrsetData['priority'];
        }

        try {
            $response = $this->client->request('POST', 'records', [
                'headers' => $this->headers,
                'json'    => $payload,
            ]);

            if ($response->getStatusCode() === 200) {
                $body     = json_decode($response->getBody()->getContents(), true);
                $recordId = $body['record']['id'] ?? null;

                if ($recordId !== null) {
                    saveRecordId($this->pdo, $domainName, $recordId, $rrsetData);
                }

                return true;
            }

            return false;
        } catch (RequestException $e) {
            throw new \Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \Exception("Error updating recordId: " . $e->getMessage());
        }
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
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (
            empty($type) ||
            !isset($rrsetData['ttl'], $rrsetData['records']) ||
            empty($rrsetData['records'])
        ) {
            throw new \Exception("Missing data for modifying RRset");
        }

        try {
            $result  = getZoneId($this->pdo, $domainName);
            $zoneId  = $result['zoneId'];
            $recordId = getRecordId($this->pdo, $domainName, $type, $subname);
        } catch (\PDOException $e) {
            throw new \Exception("Error in operation: " . $e->getMessage());
        }

        $type  = strtoupper($type);
        $ttl   = (int)$rrsetData['ttl'];
        $value = (string)$rrsetData['records'][0];

        $apiName = ($subname === '' || $subname === '@') ? '@' : $subname;

        $payload = [
            'value'   => $value,
            'ttl'     => $ttl,
            'type'    => $type,
            'name'    => $apiName,
            'zone_id' => $zoneId,
        ];

        if (in_array($type, ['MX', 'SRV'], true) && isset($rrsetData['priority'])) {
            $payload['priority'] = (int)$rrsetData['priority'];
        }

        try {
            $response = $this->client->request('PUT', "records/{$recordId}", [
                'headers' => $this->headers,
                'json'    => $payload,
            ]);

            return $response->getStatusCode() === 200;
        } catch (RequestException $e) {
            throw new \Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \Exception("Error in operation: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $recordId = getRecordId($this->pdo, $domainName, $type, $subname);

            $response = $this->client->request('DELETE', "records/{$recordId}", [
                'headers' => $this->headers,
            ]);

            return $response->getStatusCode() === 204;
        } catch (RequestException $e) {
            throw new \Exception('Request failed: ' . $e->getMessage());
        } catch (\PDOException $e) {
            throw new \Exception("Error in operation: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \exception("Not yet implemented");
    }
    
}