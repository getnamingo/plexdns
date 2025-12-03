<?php

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;

class Desec implements DnsHostingProviderInterface {
    private $baseUrl = "https://desec.io/api/v1/domains/";
    private $client;
    private $headers;

    public function __construct($config) {
        $token = $config['apikey'];
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl]);
        $this->headers = [
            'Authorization' => 'Token ' . $token,
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
        ];
    }

    public function createDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        $response = $this->client->request('POST', '', [
            'headers' => $this->headers,
            'json' => ['name' => $domainName]
        ]);

        return json_decode($response->getBody(), true);
    }

    public function listDomains() {
        $response = $this->client->request('GET', '', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function getDomain($domainName) {
        $response = $this->client->request('GET', $domainName . '/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function getResponsibleDomain($qname) {
        $response = $this->client->request('GET', '?owns_qname=' . $qname, ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function exportDomainAsZonefile($domainName) {
        $response = $this->client->request('GET', $domainName . "/zonefile/", ['headers' => $this->headers]);
        return $response->getBody();
    }

    public function deleteDomain($domainName) {
        if (empty($domainName)) {
            throw new \Exception("Domain name cannot be empty");
        }

        $response = $this->client->request('DELETE', $domainName . "/", ['headers' => $this->headers]);
        return $response->getStatusCode() === 204;
    }

    public function createRRset($domainName, $rrsetData) {
        $response = $this->client->request('POST', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetData
        ]);

        return true;
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('POST', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return json_decode($response->getBody(), true);
    }

    public function retrieveAllRRsets($domainName) {
        $response = $this->client->request('GET', $domainName . '/rrsets/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        $response = $this->client->request('GET', $domainName . '/rrsets/' . $subname . '/' . $type . '/', ['headers' => $this->headers]);
        return json_decode($response->getBody(), true);
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        $subname = $subname ?: '@';

        $response = $this->client->request('PATCH', $domainName . '/rrsets/' . $subname . '/' . $type . '/', [
            'headers' => $this->headers,
            'json' => $rrsetData
        ]);

        return json_decode($response->getBody(), true);
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('PUT', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return json_decode($response->getBody(), true);
    }

    public function deleteRRset($domainName, $subname, $type, $value = null) {
        $subname = $subname ?: '@';

        $response = $this->client->request('DELETE', $domainName . '/rrsets/' . $subname . '/' . $type . '/', ['headers' => $this->headers]);
        return $response->getStatusCode() === 204;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        $response = $this->client->request('PATCH', $domainName . '/rrsets/', [
            'headers' => $this->headers,
            'json' => $rrsetDataArray
        ]);

        return $response->getStatusCode() === 204;
    }

    public function enableDNSSEC(string $domainName): array
    {
        if ($domainName === '') {
            throw new \InvalidArgumentException('Domain name cannot be empty');
        }

        // deSEC always has DNSSEC enabled by default.
        // Just return the DS records.
        $response = $this->client->request('GET', $domainName . '/', [
            'headers' => $this->headers,
        ]);

        $data = json_decode((string)$response->getBody(), true);

        $dsRecords = [];
        if (isset($data['keys']) && is_array($data['keys'])) {
            foreach ($data['keys'] as $key) {
                if (!empty($key['ds'])) {
                    foreach ($key['ds'] as $ds) {
                        if (!in_array($ds, $dsRecords, true)) {
                            $dsRecords[] = $ds;
                        }
                    }
                }
            }
        }

        return $dsRecords;
    }

    public function disableDNSSEC(string $domainName): bool
    {
        // deSEC does not support disabling DNSSEC at all.
        throw new \Exception("DNSSEC cannot be disabled on deSEC; it is enforced.");
    }

    public function getDNSSECStatus(string $domainName): array
    {
        if ($domainName === '') {
            throw new \InvalidArgumentException('Domain name cannot be empty');
        }

        $response = $this->client->request('GET', $domainName . '/', [
            'headers' => $this->headers,
        ]);

        $data = json_decode((string)$response->getBody(), true);

        $dsRecords = [];
        $keys = $data['keys'] ?? [];

        foreach ($keys as $key) {
            if (!empty($key['ds'])) {
                foreach ($key['ds'] as $ds) {
                    if (!in_array($ds, $dsRecords, true)) {
                        $dsRecords[] = $ds;
                    }
                }
            }
        }

        return [
            'enabled' => !empty($dsRecords),
            'ds'      => $dsRecords,
            'keys'    => $keys,
        ];
    }

    public function getDSRecords(string $domainName): array
    {
        if ($domainName === '') {
            throw new \InvalidArgumentException('Domain name cannot be empty');
        }

        $response = $this->client->request('GET', $domainName . '/', [
            'headers' => $this->headers,
        ]);

        $data = json_decode((string)$response->getBody(), true);

        $dsRecords = [];

        if (isset($data['keys']) && is_array($data['keys'])) {
            foreach ($data['keys'] as $key) {
                if (!empty($key['ds'])) {
                    foreach ($key['ds'] as $ds) {
                        if (!in_array($ds, $dsRecords, true)) {
                            $dsRecords[] = $ds;
                        }
                    }
                }
            }
        }

        return $dsRecords;
    } 
}