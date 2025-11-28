<?php

namespace PlexDNS\Providers;

use Cloudflare\API\Auth\APIKey as CloudflareAPIKey;
use Cloudflare\API\Auth\APIToken as CloudflareAPIToken;
use Cloudflare\API\Adapter\Guzzle as CloudflareGuzzle;
use Cloudflare\API\Endpoints\Zones as CloudflareZones;
use Cloudflare\API\Endpoints\DNS as CloudflareDNS;
use Cloudflare\API\Endpoints\EndpointException;

class Cloudflare implements DnsHostingProviderInterface {
    private $adapter;
    private $zones;
    private $dns;
    
    public function __construct($config) {
        if (empty($config['apikey'])) {
            throw new \Exception("API key/token cannot be empty");
        }
        
        $raw = $config['apikey'];

        if (strpos($raw, ':') !== false) {
            [$email, $apiKey] = explode(':', $raw, 2);
            if (!$email || !$apiKey) {
                throw new \Exception("Invalid Cloudflare credentials. Expected 'email:global_api_key' or 'api_token'.");
            }
            $auth = new CloudflareAPIKey($email, $apiKey);
        } else {
            $auth = new CloudflareAPIToken($raw);
        }

        $this->adapter = new CloudflareGuzzle($auth);
        $this->zones = new CloudflareZones($this->adapter);
        $this->dns = new CloudflareDNS($this->adapter);
    }

    public function createDomain($domainName) {
        try {
            return $this->zones->addZone($domainName);
        } catch (EndpointException $e) {
            throw new \Exception("Error creating domain: " . $e->getMessage());
        }
    }

    public function listDomains() {
        try {
            return $this->zones->listZones()->result;
        } catch (EndpointException $e) {
            throw new \Exception("Error listing domains: " . $e->getMessage());
        }
    }

    public function getDomain($domainName) {
        try {
            $zone = $this->zones->getZoneID($domainName);
            $zoneDetails = $this->zones->getZoneByID($zone);

            return [
                'id' => $zoneDetails->id,
                'name' => $zoneDetails->name,
                'status' => $zoneDetails->status,
                'name_servers' => $zoneDetails->name_servers,
                'created_at' => $zoneDetails->created_on,
                'modified_at' => $zoneDetails->modified_on,
            ];
        } catch (EndpointException $e) {
            throw new \Exception("Error retrieving domain details: " . $e->getMessage());
        }
    }

    public function getResponsibleDomain($qname) {
        throw new \Exception("Not yet implemented");
    }

    public function exportDomainAsZonefile($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteDomain($domainName) {
        try {
            $zoneId = $this->zones->getZoneID($domainName);
            return $this->zones->deleteZone($zoneId);
        } catch (EndpointException $e) {
            throw new \Exception("Error deleting domain: " . $e->getMessage());
        }
    }

    public function createRRset($domainName, $rrsetData) {
        try {
            $zoneId = $this->zones->getZoneID($domainName);
            $priority = isset($rrsetData['priority']) ? (string) $rrsetData['priority'] : '';

            $sub = $rrsetData['subname'] ?? '';
            $name = ($sub === '' || $sub === '@')
                ? $domainName
                : $sub . '.' . $domainName;

            return $this->dns->addRecord(
                $zoneId,
                $rrsetData['type'],
                $name,
                $rrsetData['records'][0],
                isset($rrsetData['ttl']) ? (int)$rrsetData['ttl'] : 1,
                false,
                $priority
            );
        } catch (\Cloudflare\API\Endpoints\EndpointException $e) {
            throw new \Exception("Error creating record: " . $e->getMessage());
        }
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function retrieveAllRRsets($domainName) {
        try {
            $zoneId = $this->zones->getZoneID($domainName);
            return $this->dns->listRecords($zoneId);
        } catch (EndpointException $e) {
            throw new \Exception("Error retrieving records: " . $e->getMessage());
        }
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        try {
            $zoneId = $this->zones->getZoneID($domainName);
            return $this->dns->listRecords($zoneId, $type, $subname);
        } catch (EndpointException $e) {
            throw new \Exception("Error retrieving specific RRset: " . $e->getMessage());
        }
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData)
    {
        try {
            $zoneId = $this->zones->getZoneID($domainName);

            $targetName = ($subname === '' || $subname === '@')
                ? $domainName
                : $subname . '.' . $domainName;

            $records = $this->dns->listRecords($zoneId, $type, $targetName);

            if (empty($records->result)) {
                throw new \Exception("No matching records found for $targetName ($type)");
            }

            $newContent = is_array($rrsetData['records'])
                ? (string) reset($rrsetData['records'])
                : (string) $rrsetData['records'];

            $lookupContent = isset($rrsetData['old_value']) && $rrsetData['old_value'] !== ''
                ? (string) $rrsetData['old_value']
                : $newContent;

            foreach ($records->result as $record) {
                $recordName   = strtolower($record->name);
                $expectedName = strtolower($targetName);

                if (
                    $recordName === $expectedName &&
                    $record->type === strtoupper($type) &&
                    $record->content === $lookupContent
                ) {
                    $details = [
                        'type'    => strtoupper($type),
                        'name'    => $record->name,
                        'content' => $newContent,
                    ];

                    if (isset($rrsetData['ttl'])) {
                        $details['ttl'] = (int) $rrsetData['ttl'];
                    }

                    if (in_array(strtoupper($type), ['MX', 'SRV'], true)) {
                        if (isset($rrsetData['priority'])) {
                            $details['priority'] = (int) $rrsetData['priority'];
                        } elseif (isset($record->priority)) {
                            $details['priority'] = (int) $record->priority;
                        }
                    }

                    return $this->dns->updateRecordDetails($zoneId, $record->id, $details);
                }
            }

            throw new \Exception("Record not found for $targetName ($type / $lookupContent)");
        } catch (\Exception $e) {
            throw new \Exception("Error modifying record: " . $e->getMessage());
        }
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value)
    {
        try {
            $zoneId = $this->zones->getZoneID($domainName);

            $targetName = ($subname === '' || $subname === '@' || $subname === null)
                ? $domainName
                : $subname . '.' . $domainName;

            $records = $this->dns->listRecords($zoneId, $type, $targetName);

            if (empty($records->result)) {
                throw new \Exception("No matching records found for $targetName ($type)");
            }

            foreach ($records->result as $record) {
                $recordName   = strtolower($record->name);
                $expectedName = strtolower($targetName);

                if (
                    $recordName === $expectedName &&
                    $record->type === strtoupper($type) &&
                    ($value === null || $value === '' || $record->content === $value)
                ) {
                    $this->dns->deleteRecord($zoneId, $record->id);
                    return "Record deleted: $expectedName ($type" . ($value !== null ? " -> $value" : "") . ")";
                }
            }

            throw new \Exception("Record not found for $targetName ($type)");
        } catch (\Exception $e) {
            throw new \Exception("Error deleting record: " . $e->getMessage());
        }
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }
}