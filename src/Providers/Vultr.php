<?php

namespace PlexDNS\Providers;

use Vultr\VultrPhp\Services\DNS\DNSService;
use Vultr\VultrPhp\Services\DNS\Domain;
use Vultr\VultrPhp\Services\DNS\Record;
use Vultr\VultrPhp\Services\DNS\DNSSOA;
use Vultr\VultrPhp\Services\DNS\DNSException;
use Vultr\VultrPhp\VultrClient;

class Vultr implements DnsHostingProviderInterface {
    private $client;
    
    public function __construct($config) {
        $token = $config['apikey'];
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }

        $this->client = VultrClient::create($token);
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        try {
            $response = $this->client->dns->createDomain($domainName);
            return json_decode($response->getDomain(), true);
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
            $response = $this->client->dns->deleteDomain($domainName);
            return true;
        } catch (\Exception $e) {
            throw new \Exception("Error deleting domain: " . $e->getMessage());
        }
    }

    public function createRRset($domainName, $rrsetData) {
        try {
            $record = new Record();

            if (isset($rrsetData['type'])) {
                $record->setType($rrsetData['type']);
            }
            if (isset($rrsetData['subname'])) {
                $record->setName($rrsetData['subname']);
            }
            if (isset($rrsetData['records'])) {
                $record->setData($rrsetData['records'][0]);
            }
            $type = strtoupper($rrsetData['type']);
            if (in_array($type, ['MX', 'SRV'], true)) {
                $priority = isset($rrsetData['priority']) ? (int)$rrsetData['priority'] : 10;
                $record->setPriority($priority);
            }
            if (isset($rrsetData['ttl'])) {
                $record->setTtl($rrsetData['ttl']);
            }

            $response = $this->client->dns->createRecord($domainName, $record);

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

    public function modifyRRset($domainName, $subname, $type, $rrsetData)
    {
        try {
            $subname = $subname ?? '';
            $lookupData = $rrsetData['old_value'] ?? ($rrsetData['records'][0] ?? null);

            if ($lookupData === null) {
                throw new \Exception("No value provided to locate record.");
            }

            $records  = $this->client->dns->getRecords($domainName);
            $recordId = null;

            foreach ($records as $record) {
                if (!$record instanceof Record) {
                    continue;
                }

                if ($record->getType() !== $type) {
                    continue;
                }

                if ($record->getName() !== $subname) {
                    continue;
                }

                if ($record->getData() !== $lookupData) {
                    continue;
                }

                $recordId = $record->getId();
                break;
            }

            if ($recordId === null) {
                throw new \Exception("Error: No record found with name '$subname', type '$type' and value '$lookupData'");
            }

            $record = new Record();
            $record->setId($recordId);
            $record->setType($type);
            $record->setName($subname);

            if (!empty($rrsetData['records'][0])) {
                $record->setData($rrsetData['records'][0]);
            }

            if (in_array(strtoupper($type), ['MX', 'SRV'], true)) {
                if (isset($rrsetData['priority'])) {
                    $record->setPriority((int)$rrsetData['priority']);
                }
            }

            if (isset($rrsetData['ttl'])) {
                $record->setTtl((int)$rrsetData['ttl']);
            }

            $this->client->dns->updateRecord($domainName, $record);

            return json_decode($domainName, true);
        } catch (\Exception $e) {
            throw new \Exception("Error updating record: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value) {
        try {
            $records = $this->client->dns->getRecords($domainName);
            $recordId = null;

            foreach ($records as $record) {
                if ($record instanceof \Vultr\VultrPhp\Services\DNS\Record) {
                    if ($type === 'MX') {
                        // For MX records, compare type, and data
                        if ($record->getType() === $type && $record->getData() === $value) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    } else {
                        // For non-MX records, compare only name and type
                        if ($record->getName() === $subname && $record->getType() === $type) {
                            $recordId = $record->getId();
                            break; // Stop the loop once the record is found
                        }
                    }
                }
            }

            if ($recordId === null) {
                throw new \Exception("Error: No record found with name '$subname' and type '$type'");
            }

            $response = $this->client->dns->deleteRecord($domainName, $recordId);
            return json_decode($domainName, true);
        } catch (\Exception $e) {
            throw new \Exception("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
    
}