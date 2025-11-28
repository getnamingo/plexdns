<?php

namespace PlexDNS\Providers;

use Dnsimple\Client;
use PDO;

class DNSimple implements DnsHostingProviderInterface {
    private $client;
    private $account_id;
    private PDO $pdo;
    
    public function __construct($config, PDO $pdo) {
        $this->pdo = $pdo;

        $token = $config['apikey'];
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }

        $this->client = new Client($token);
        $this->account_id = $this->client->identity->whoami()->getData()->account->id;
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        try {
            $response = $this->client->domains->createDomain(
                $this->account_id,
                ['name' => $domainName]
            );

            return $response->getData();
        } catch (\Exception $e) {
            throw new \Exception("Error creating domain: " . $e->getMessage());
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
            $this->client->domains->deleteDomain($this->account_id, $domainName);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Error deleting domain: " . $e->getMessage());
        }
    }
    
    public function createRRset($domainName, $rrsetData) {
        try {
            $record = [];

            if (!empty($rrsetData['type'])) {
                $record['type'] = strtoupper($rrsetData['type']);
            }

            if (array_key_exists('subname', $rrsetData)) {
                $subname = (string)$rrsetData['subname'];
                $record['name'] = ($subname === '@') ? '' : $subname;
            }

            if (!empty($rrsetData['records'])) {
                $record['content'] = (string)$rrsetData['records'][0];
            }

            if (isset($rrsetData['ttl'])) {
                $record['ttl'] = (int)$rrsetData['ttl'];
            }

            if (
                isset($record['type']) &&
                in_array($record['type'], ['MX', 'SRV'], true) &&
                isset($rrsetData['priority'])
            ) {
                $record['priority'] = (int)$rrsetData['priority'];
            }

            $response = $this->client->zones->createRecord($this->account_id, $domainName, $record);
            $recordId = $response->getData()->id ?? null;

            if ($recordId !== null) {
                try {
                    saveRecordId($this->pdo, $domainName, $recordId, $rrsetData);
                } catch (\Exception $e) {
                }
            }

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Error creating record: " . $e->getMessage());
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
        try {
            $recordId = getRecordId($this->pdo, $domainName, $type, $subname);

            $record = [];

            if (!empty($type)) {
                $record['type'] = strtoupper($type);
            }

            if ($subname !== null) {
                $name = (string)$subname;
                $record['name'] = ($name === '@') ? '' : $name;
            }

            if (!empty($rrsetData['records'])) {
                $record['content'] = (string)$rrsetData['records'][0];
            }

            if (isset($rrsetData['ttl'])) {
                $record['ttl'] = (int)$rrsetData['ttl'];
            }

            if (
                !empty($type) &&
                in_array(strtoupper($type), ['MX', 'SRV'], true) &&
                isset($rrsetData['priority'])
            ) {
                $record['priority'] = (int)$rrsetData['priority'];
            }

            $this->client->zones->updateRecord($this->account_id, $domainName, $recordId, $record);

            return true;
        } catch (\PDOException $e) {
            throw new \Exception("Error in operation: " . $e->getMessage());
        } catch (\Exception $e) {
            throw new \Exception("Error updating record: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $recordId = getRecordId($this->pdo, $domainName, $type, $subname);

            $this->client->zones->deleteRecord($this->account_id, $domainName, $recordId);

            return true;
        } catch (\Exception $e) {
            throw new \Exception("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
    
}