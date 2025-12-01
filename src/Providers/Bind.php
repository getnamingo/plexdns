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
use Spatie\Dns\Dns;

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

        if (!isset($subname, $type, $rrsetData['ttl'], $rrsetData['records'])) {
            throw new \Exception("Missing data for creating RRset");
        }

        $recordValue = $rrsetData['records'][0];
        $fqdn = ($subname === '@') ? ltrim($domainName, '.') : "$subname.$domainName"; 

        $dns = new Dns();
        $dns->useNameserver($this->api_ip);
        $record = $dns->getRecords($fqdn, strtoupper($type))[0];
        
        // Check if the desired record type was found
        if (empty($record)) {
            throw new \Exception("Failed to retrieve current $type record for $fqdn");
        }

        $recordString = (string)$record;
        $recordParts = preg_split('/\s+/', trim($recordString));

        // Handle MX record differently by checking if the type is 'MX'
        $recordType = strtoupper($recordParts[3]); // The 4th element is the type (e.g., 'A', 'MX', 'TXT')
        if ($recordType === 'MX') {
            // For MX, the second last element is the actual value
            $currentRecordValue = $recordParts[4] . ' ' . end($recordParts);
        } else {
            // For other types, take the last element
            $currentRecordValue = end($recordParts);
        }

        if ($currentRecordValue === null) {
            throw new \Exception("Failed to retrieve current $type record for $fqdn");
        }

        // Prepare the current record for the update
        $currentRecord = [
            'name' => $subname,
            'type' => $type,
            'rdata' => $currentRecordValue,
        ];
        $newRecord = [
            'rdata' => $recordValue
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

        if (!isset($subname, $type, $value)) {
            throw new \Exception("Missing data for creating RRset");
        }
        
        $record = [
            'name' => $subname,
            'type' => $type,
            'rdata' => $value
        ];

        $this->client->deleteRecord($domainName, $record);
        
        return json_decode($domainName, true);
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

}