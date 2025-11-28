<?php

/* $config = [
    // Master PowerDNS API
    'apikey' => 'master_api_key',
    'powerdnsip' => '127.0.0.1',

    // Master IP (for slave servers to sync from)
    'pdns_master_ip' => '192.168.1.1',

    // Nameservers (NS1 to NS13)
    'ns1' => 'ns1.example.com.',
    'ns2' => 'ns2.example.com.',
    'ns3' => 'ns3.example.com.',
    'ns4' => 'ns4.example.com.',
    'ns5' => 'ns5.example.com.',
    'ns6' => 'ns6.example.com.',
    'ns7' => 'ns7.example.com.',
    'ns8' => 'ns8.example.com.',
    'ns9' => 'ns9.example.com.',
    'ns10' => 'ns10.example.com.',
    'ns11' => 'ns11.example.com.',
    'ns12' => 'ns12.example.com.',
    'ns13' => 'ns13.example.com.',

    // Slave PowerDNS APIs (NS2 to NS13)
    'apikey_ns2' => 'slave2_api_key',
    'powerdnsip_ns2' => '192.168.1.2',

    'apikey_ns3' => 'slave3_api_key',
    'powerdnsip_ns3' => '192.168.1.3',

    'apikey_ns4' => 'slave4_api_key',
    'powerdnsip_ns4' => '192.168.1.4',

    'apikey_ns5' => 'slave5_api_key',
    'powerdnsip_ns5' => '192.168.1.5',

    'apikey_ns6' => 'slave6_api_key',
    'powerdnsip_ns6' => '192.168.1.6',

    'apikey_ns7' => 'slave7_api_key',
    'powerdnsip_ns7' => '192.168.1.7',

    'apikey_ns8' => 'slave8_api_key',
    'powerdnsip_ns8' => '192.168.1.8',

    'apikey_ns9' => 'slave9_api_key',
    'powerdnsip_ns9' => '192.168.1.9',

    'apikey_ns10' => 'slave10_api_key',
    'powerdnsip_ns10' => '192.168.1.10',

    'apikey_ns11' => 'slave11_api_key',
    'powerdnsip_ns11' => '192.168.1.11',

    'apikey_ns12' => 'slave12_api_key',
    'powerdnsip_ns12' => '192.168.1.12',

    'apikey_ns13' => 'slave13_api_key',
    'powerdnsip_ns13' => '192.168.1.13',
]; */

namespace PlexDNS\Providers;

use Exonet\Powerdns\Powerdns as PowerdnsApi;
use Exonet\Powerdns\RecordType;
use Exonet\Powerdns\Resources\ResourceRecord;
use Exonet\Powerdns\Resources\Record;
use Exonet\Powerdns\Resources\Zone as ZoneResource;

class PowerDNS implements DnsHostingProviderInterface {
    private $client;
    private $nsRecords;
    private $slaveClients = [];
    private $masterIp;

    public function __construct($config) {
        $token = $config['apikey'];
        $api_ip = $config['powerdnsip'];
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }
        if (empty($api_ip)) {
            $api_ip = '127.0.0.1';
        }

        $this->nsRecords = [];
        for ($i = 1; $i <= 13; $i++) {
            $key = "ns{$i}";
            if (!empty($config[$key])) {
                $this->nsRecords[$key] = $config[$key];
            }
        }

        $this->client = new PowerdnsApi($api_ip, $token);

        if (!empty($config['pdns_master_ip'])) {
            $this->masterIp = $config['pdns_master_ip'];
            for ($i = 2; $i <= 13; $i++) {
                $slaveApiKey = $config["apikey_ns{$i}"] ?? null;
                $slaveApiIp = $config["powerdnsip_ns{$i}"] ?? null;
                if ($slaveApiKey && $slaveApiIp) {
                    $this->slaveClients[$i] = new PowerdnsApi($slaveApiIp, $slaveApiKey);
                }
            }
        }
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        $nsRecords = array_filter($this->nsRecords);
        $formattedNsRecords = array_values(
            array_map(
                static fn($nsRecord) => rtrim($nsRecord, '.') . '.',
                $nsRecords
            )
        );

        try {
            $this->client->createZone($domainName, $formattedNsRecords);

            if (!empty($this->masterIp)) {
                foreach ($this->slaveClients as $slaveClient) {
                    $newZone = new ZoneResource();
                    $newZone->setName($domainName);
                    $newZone->setKind('Slave');
                    $newZone->setMasters([$this->masterIp]);
                    $slaveClient->createZoneFromResource($newZone);
                }
            }

            return true;
        } catch (\Exception $e) {
            if (strpos($e->getMessage(), 'Conflict') !== false) {
                throw new \Exception("Zone already exists for domain: " . $domainName);
            }

            throw new \Exception(
                "Failed to create zone for domain: " . $domainName . ". Error: " . $e->getMessage()
            );
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

        $this->client->deleteZone($domainName);

        foreach ($this->slaveClients as $slaveClient) {
            $slaveClient->deleteZone($domainName);
        }

        return json_decode($domainName, true);
    }

    public function createRRset($domainName, $rrsetData) {
        $zone = $this->client->zone($domainName);

        if (
            empty($domainName) ||
            !isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records']) ||
            empty($rrsetData['records'])
        ) {
            throw new \Exception("Missing data for creating RRset");
        }

        $subname = $rrsetData['subname'];
        $type    = strtoupper($rrsetData['type']);
        $ttl     = (int)$rrsetData['ttl'];
        $value   = (string)$rrsetData['records'][0];

        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \Exception("Invalid record type");
        }

        if ($subname === '' || $subname === '@') {
            $name = $domainName;
        } else {
            $name = $subname;
        }

        if ($type === 'MX' && isset($rrsetData['priority'])) {
            $prio  = (int)$rrsetData['priority'];
            $value = $prio . ' ' . $value;
        }

        $zone->create($name, $recordType, $value, $ttl);

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
        $zone = $this->client->zone($domainName);

        if (
            empty($domainName) ||
            !isset($subname, $type, $rrsetData['ttl'], $rrsetData['records']) ||
            empty($rrsetData['records'])
        ) {
            throw new \Exception("Missing data for modifying RRset");
        }

        $type  = strtoupper($type);
        $ttl   = (int)$rrsetData['ttl'];
        $value = (string)$rrsetData['records'][0];

        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \Exception("Invalid record type");
        }

        if ($subname === '' || $subname === '@') {
            $name = $domainName;
        } else {
            $name = $subname;
        }

        if ($type === 'MX' && isset($rrsetData['priority'])) {
            $prio  = (int)$rrsetData['priority'];
            $value = $prio . ' ' . $value;
        }

        $existing = $zone->find($name, $recordType);
        if ($existing instanceof ResourceRecord) {
            $existing->delete();
        }

        $zone->create($name, $recordType, $value, $ttl);

        return json_decode($domainName, true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        $zone = $this->client->zone($domainName);

        if (empty($domainName) || !isset($subname, $type)) {
            throw new \Exception("Missing data for deleting RRset");
        }

        $type = strtoupper($type);

        switch ($type) {
            case 'A':
                $recordType = RecordType::A;
                break;
            case 'AAAA':
                $recordType = RecordType::AAAA;
                break;
            case 'CNAME':
                $recordType = RecordType::CNAME;
                break;
            case 'MX':
                $recordType = RecordType::MX;
                break;
            case 'TXT':
                $recordType = RecordType::TXT;
                break;
            case 'SPF':
                $recordType = RecordType::SPF;
                break;
            case 'DS':
                $recordType = RecordType::DS;
                break;
            default:
                throw new \Exception("Invalid record type");
        }

        if ($subname === '' || $subname === '@') {
            $name = $domainName;
        } else {
            $name = $subname;
        }

        $existing = $zone->find($name, $recordType);
        if ($existing instanceof ResourceRecord) {
            $existing->delete();
        }

        return json_decode($domainName, true);
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

}
