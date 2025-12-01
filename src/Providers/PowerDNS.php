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

    public function createRRset($domainName, $rrsetData)
    {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (
            !isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl'], $rrsetData['records']) ||
            !is_array($rrsetData['records']) ||
            count($rrsetData['records']) === 0
        ) {
            throw new \Exception("Missing data for creating RRset");
        }

        $zone    = $this->client->zone($domainName);
        $subname = $rrsetData['subname'];
        $type    = strtoupper($rrsetData['type']);
        $ttl     = (int)$rrsetData['ttl'];

        if (!defined(RecordType::class . '::' . $type)) {
            throw new \Exception("Invalid record type");
        }
        $recordType = constant(RecordType::class . '::' . $type);

        $name = ($subname === '' || $subname === '@') ? '@' : $subname;

        $zoneRootNoDot  = rtrim($domainName, '.');
        $zoneRootWithDot = $zoneRootNoDot . '.';

        if ($name === '@') {
            $candidateNames = ['@', $zoneRootNoDot, $zoneRootWithDot];
        } else {
            $ownerShort       = $name;
            $ownerFqdnNoDot   = $ownerShort . '.' . $zoneRootNoDot;
            $ownerFqdnWithDot = $ownerFqdnNoDot . '.';

            $candidateNames = [$ownerShort, $ownerFqdnNoDot, $ownerFqdnWithDot];
        }

        $all            = $zone->get();
        $existingSet    = null;
        $existingTtl    = $ttl;
        $existingValues = [];

        foreach ($all as $rrset) {
            if (in_array($rrset->getName(), $candidateNames, true) && $rrset->getType() === $recordType) {
                $existingSet = $rrset;

                if ($rrset->getTtl() !== null) {
                    $existingTtl = $rrset->getTtl();
                }

                foreach ($rrset->getRecords() as $rec) {
                    $existingValues[] = $rec->getContent();
                }

                break;
            }
        }

        $mergedValues = $existingValues;

        foreach ($rrsetData['records'] as $val) {
            $value = (string)$val;

            if ($type === 'MX' && isset($rrsetData['priority'])) {
                $prio = (int)$rrsetData['priority'];
                $host = rtrim($value, '.');
                $value = $prio . ' ' . $host . '.';
            }

            if (!in_array($value, $mergedValues, true)) {
                $mergedValues[] = $value;
            }
        }

        if (empty($mergedValues)) {
            return true;
        }

        if ($existingSet instanceof ResourceRecord) {
            $existingSet->delete();
        }

        $rrset = new ResourceRecord();
        $rrset->setName($name);
        $rrset->setType($recordType);
        $rrset->setTtl($existingTtl);

        foreach ($mergedValues as $val) {
            $record = new Record();
            $record->setContent($val);
            $rrset->addRecord($record);
        }

        $zone->create($rrset);

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
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (
            !isset($rrsetData['ttl'], $rrsetData['records']) ||
            !is_array($rrsetData['records']) ||
            count($rrsetData['records']) === 0
        ) {
            throw new \Exception("Missing data for modifying RRset");
        }

        $zone = $this->client->zone($domainName);

        $type = strtoupper($type);
        if (!defined(RecordType::class . '::' . $type)) {
            throw new \Exception("Invalid record type");
        }
        $recordType = constant(RecordType::class . '::' . $type);

        $name = ($subname === '' || $subname === '@') ? '@' : $subname;

        $zoneRootNoDot   = rtrim($domainName, '.');
        $zoneRootWithDot = $zoneRootNoDot . '.';

        if ($name === '@') {
            $candidateNames = ['@', $zoneRootNoDot, $zoneRootWithDot];
        } else {
            $ownerShort       = $name;
            $ownerFqdnNoDot   = $ownerShort . '.' . $zoneRootNoDot;
            $ownerFqdnWithDot = $ownerFqdnNoDot . '.';

            $candidateNames = [$ownerShort, $ownerFqdnNoDot, $ownerFqdnWithDot];
        }

        $all            = $zone->get();
        $existingSet    = null;
        $existingTtl    = (int)$rrsetData['ttl'];
        $existingValues = [];

        foreach ($all as $rrset) {
            if (in_array($rrset->getName(), $candidateNames, true) && $rrset->getType() === $recordType) {
                $existingSet = $rrset;

                if ($rrset->getTtl() !== null) {
                    $existingTtl = $rrset->getTtl();
                }

                foreach ($rrset->getRecords() as $rec) {
                    $existingValues[] = $rec->getContent();
                }

                break;
            }
        }

        $old = rtrim($rrsetData['old_value'] ?? '', '.');
        $new = rtrim($rrsetData['records'][0], '.');

        if ($type === 'MX' && isset($rrsetData['priority'])) {
            $prio = (int)$rrsetData['priority'];
            $new  = $prio . ' ' . $new . '.';
        }

        $mergedValues = [];

        foreach ($existingValues as $content) {

            if ($recordType === RecordType::MX) {
                $parts      = preg_split('/\s+/', trim($content), 2);
                $prioOldRec = $parts[0] ?? '';
                $hostOldRec = rtrim($parts[1] ?? '', '.');

                $normContent = $hostOldRec;
                $normOld     = rtrim($old, '.');
            } else {
                $normContent = rtrim($content, '.');
                $normOld     = rtrim($old, '.');
            }

            if ($normContent === $normOld) {
                $mergedValues[] = $new;
            } else {
                $mergedValues[] = $content;
            }
        }

        if (!in_array($new, $mergedValues, true)) {
            $mergedValues[] = $new;
        }

        if (empty($mergedValues)) {
            return true;
        }

        if ($existingSet instanceof ResourceRecord) {
            $existingSet->delete();
        }

        $rrset = new ResourceRecord();
        $rrset->setName($name);
        $rrset->setType($recordType);
        $rrset->setTtl($existingTtl);

        foreach ($mergedValues as $val) {
            $record = new Record();
            $record->setContent($val);
            $rrset->addRecord($record);
        }

        $zone->create($rrset);

        return true;
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value)
    {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        if (!isset($subname, $type) || $value === null || $value === '') {
            throw new \Exception("Missing data for deleting RRset");
        }

        $zone = $this->client->zone($domainName);

        $type = strtoupper($type);
        if (!defined(RecordType::class . '::' . $type)) {
            throw new \Exception("Invalid record type");
        }
        $recordType = constant(RecordType::class . '::' . $type);

        $name = ($subname === '' || $subname === '@') ? '@' : $subname;
        $value = (string)$value;

        $zoneRootNoDot   = rtrim($domainName, '.');
        $zoneRootWithDot = $zoneRootNoDot . '.';

        if ($name === '@') {
            $candidateNames = ['@', $zoneRootNoDot, $zoneRootWithDot];
        } else {
            $ownerShort       = $name;
            $ownerFqdnNoDot   = $ownerShort . '.' . $zoneRootNoDot;
            $ownerFqdnWithDot = $ownerFqdnNoDot . '.';

            $candidateNames = [$ownerShort, $ownerFqdnNoDot, $ownerFqdnWithDot];
        }

        $all           = $zone->get();
        $existingSet   = null;
        $existingTtl   = null;
        $existingValues = [];

        foreach ($all as $rrset) {
            if (in_array($rrset->getName(), $candidateNames, true) && $rrset->getType() === $recordType) {
                $existingSet = $rrset;

                if ($rrset->getTtl() !== null) {
                    $existingTtl = $rrset->getTtl();
                }

                foreach ($rrset->getRecords() as $rec) {
                    $existingValues[] = $rec->getContent();
                }

                break;
            }
        }

        if (!$existingSet instanceof ResourceRecord || empty($existingValues)) {
            return true;
        }

        $remaining = [];
        $normValue = rtrim($value, '.');

        foreach ($existingValues as $content) {
            if ($recordType === RecordType::MX) {
                $parts  = preg_split('/\s+/', $content, 2);
                $target = $parts[1] ?? $content;

                $normContent = rtrim($content, '.');
                $normTarget  = rtrim($target, '.');

                if ($normContent === $normValue || $normTarget === $normValue) {
                    continue;
                }
            } else {
                if (rtrim($content, '.') === $normValue) {
                    continue;
                }
            }

            $remaining[] = $content;
        }

        $existingSet->delete();

        if (!empty($remaining)) {
            $rrset = new ResourceRecord();
            $rrset->setName($name);
            $rrset->setType($recordType);

            if ($existingTtl !== null) {
                $rrset->setTtl($existingTtl);
            }

            foreach ($remaining as $content) {
                $record = new Record();
                $record->setContent($content);
                $rrset->addRecord($record);
            }

            $zone->create($rrset);
        }

        return true;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

}