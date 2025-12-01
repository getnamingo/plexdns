<?php

/* $config = [
    'apikey' => 'masterUser:masterPass',
    'bindip' => '192.168.1.100',  // Master BIND9 server IP

    // Optional: Add slave servers dynamically
    'apikey_ns2' => 'slaveUser1:slavePass1',
    'bindip_ns2' => '192.168.1.101',

    'apikey_ns3' => 'slaveUser2:slavePass2',
    'bindip_ns3' => '192.168.1.102',
]; */

namespace PlexDNS\Providers;

use Namingo\Bind9Api\ApiClient;

class Bind implements DnsHostingProviderInterface {
    private $client;
    private $api_ip;
    private $slaveClients = [];

    public function __construct($config) {
        $token = $config['apikey'];
        $api_ip = $config['bindip'];

        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }
        if (empty($api_ip)) {
            $api_ip = '127.0.0.1';
        }

        // Split the token into username and password
        list($username, $password) = explode(':', $token, 2);

        if (empty($username) || empty($password)) {
            throw new \Exception("API token must be in the format 'username:password'");
        }

        $this->api_ip = $api_ip;
        $this->client = new ApiClient('http://'.$api_ip.':7650');
        $this->client->login($username, $password);

        for ($i = 2; $i <= 13; $i++) {
            $slaveApiKey = $config["apikey_ns$i"] ?? null;
            $slaveApiIp = $config["bindip_ns$i"] ?? null;

            if ($slaveApiKey && $slaveApiIp) {
                list($slaveUser, $slavePass) = explode(':', $slaveApiKey, 2);
                
                if (!empty($slaveUser) && !empty($slavePass)) {
                    $slaveClient = new ApiClient('http://' . $slaveApiIp . ':7650');
                    $slaveClient->login($slaveUser, $slavePass);
                    $this->slaveClients[] = $slaveClient;
                }
            }
        }
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        try {
            $this->client->addZone($domainName);

            foreach ($this->slaveClients as $slaveClient) {
                $slaveClient->addSlaveZone($domainName, $this->api_ip);
            }

            return true;
        } catch (\Exception $e) {
            // Throw an exception to indicate failure, including for conflicts.
            if (strpos($e->getMessage(), 'Conflict') !== false) {
                throw new \Exception("Zone already exists for domain: " . $domainName);
            } else {
                throw new \Exception("Failed to create zone for domain: " . $domainName . ". Error: " . $e->getMessage());
            }
        }
    }

    public function listDomains() {
        throw new \Exception("Not yet implemented");
    }

    public function getDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        try {
            $records = $this->client->getRecords($domainName);
            return $records;
        } catch (\Exception $e) {
           throw new \Exception("Failed to fetch zone: " . $domainName . ". Error: " . $e->getMessage());
        }
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
            foreach ($this->slaveClients as $slaveClient) {
                $slaveClient->deleteSlaveZone($domainName);
            }

            $this->client->deleteZone($domainName);

            return json_decode($domainName, true);
        } catch (\Exception $e) {
            throw new \Exception("Failed to delete zone for domain: " . $domainName . ". Error: " . $e->getMessage());
        }
    }

    public function createRRset($domainName, $rrsetData) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (!isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records'])) {
            throw new \Exception("Missing data for creating RRset");
        }

        if (strtoupper($rrsetData['type']) === 'MX') {
            $priority = (int)($rrsetData['priority'] ?? 10);
            $exchange = rtrim($rrsetData['records'][0], '.');
 
            $rdata = "$priority $exchange";
        } else {
            $rdata = $rrsetData['records'][0];
        }

        $record = [
            'name' => $rrsetData['subname'],
            'type' => $rrsetData['type'],
            'ttl' => $rrsetData['ttl'],
            'rdata' => $rdata
        ];

        try {
            $this->client->addRecord($domainName, $record);
            return true;
        } catch (\Exception $e) {
           throw new \Exception("Error creating record: " . $e->getMessage());
        }

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
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if ($subname === null || $subname === '') {
            $subname = '@';
        }

        if (
            empty($type) ||
            !isset($rrsetData['ttl'], $rrsetData['records']) ||
            empty($rrsetData['records'])
        ) {
            throw new \Exception("Missing data for creating RRset");
        }

        // old value from hidden field
        $oldValue = $rrsetData['old_value'] ?? null;
        if ($oldValue === null || $oldValue === '') {
            throw new \Exception("Missing old_value for RRset update");
        }

        $recordValue = $rrsetData['records'][0];

        // Prepare the current record for the update
        $currentRecord = [
            'name'  => $subname,
            'type'  => $type,
            'rdata' => $oldValue,
        ];

        $newRecord = [
            'rdata' => $recordValue,
            'ttl'   => $rrsetData['ttl'],
        ];

        $this->client->updateRecord($domainName, $currentRecord, $newRecord);

        return json_decode($domainName, true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if ($subname === null || $subname === '') {
            $subname = '@';
        }

        if (empty($type) || $value === null || $value === '') {
            throw new \Exception("Missing data for deleting RRset");
        }

        $record = [
            'name'  => $subname,
            'type'  => strtoupper($type),
            'rdata' => $value,
        ];

        $this->client->deleteRecord($domainName, $record);

        return json_decode($domainName, true);
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

}