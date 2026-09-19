<?php

namespace PlexDNS\Providers;

use GuzzleHttp\Client;
use PDO;
use Badcow\DNS\Rdata\TXT;

class Hetzner implements DnsHostingProviderInterface {
    private $baseUrl = "https://api.hetzner.cloud/v1/";
    private $client;
    private $headers;

    // Keep the PDO argument for compatibility; Service owns persistence.
    public function __construct($config, PDO $pdo) {
        $token = $config['apikey'] ?? null;
        if (empty($token)) {
            throw new \Exception("API token cannot be empty");
        }

        $this->client = new Client(['base_uri' => $this->baseUrl, 'timeout' => 15, 'connect_timeout' => 5, 'allow_redirects' => false]);
        $this->headers = [
            'Authorization' => 'Bearer ' . $token,
            'Content-Type' => 'application/json',
        ];
    }

    public function createDomain($domainName) {
        $body = $this->request('POST', 'zones', ['json' => [
            'name' => $this->domainName($domainName),
            'mode' => 'primary',
        ]]);
        $this->waitForAction($body);
        if (empty($body['zone']['id'])) {
            throw new \RuntimeException('Hetzner API did not return a zone ID.');
        }
        // Service inserts the local zone after this call and recognizes Id.
        $body['Id'] = (string)$body['zone']['id'];
        return $body;
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
        $this->waitForAction($this->request('DELETE', $this->zonePath($domainName)));
        return true;
    }

    /** Optional $createdRecordId output preserves the boolean return value. */
    public function createRRset($domainName, $rrsetData, &$createdRecordId = null) {
        // Cloud DNS identifies an RRSet by name/type and each value by its RDATA.
        $createdRecordId = null;
        if (!isset($rrsetData['subname'], $rrsetData['type'], $rrsetData['ttl']) || empty($rrsetData['records'])) {
            throw new \InvalidArgumentException('Missing data for creating RRset.');
        }
        $ttl = $this->ttl($rrsetData['ttl']);
        $records = [];
        foreach ($rrsetData['records'] as $value) {
            $records[] = ['value' => $this->recordValue($rrsetData['type'], $value, $rrsetData)];
        }
        $path = $this->rrsetPath($domainName, $rrsetData['subname'], $rrsetData['type']);
        // add_records creates a missing RRSet and preserves existing sibling values.
        $this->waitForAction($this->request('POST', $path . '/actions/add_records', ['json' => [
            'ttl' => $ttl,
            'records' => $records,
        ]]));
        return true;
    }

    public function createBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function retrieveAllRRsets($domainName) {
        throw new \Exception("Not yet implemented");
    }

    public function sync(PDO $db, string $domainName): int {
        throw new \PlexDNS\UnsupportedProviderException('Hetzner synchronization is not supported.');
    }

    public function retrieveSpecificRRset($domainName, $subname, $type) {
        throw new \Exception("Not yet implemented");
    }

    public function modifyRRset($domainName, $subname, $type, $rrsetData) {
        if (!isset($rrsetData['ttl']) || count($rrsetData['records'] ?? []) !== 1) {
            throw new \InvalidArgumentException('A TTL and exactly one new record value are required.');
        }
        $ttl = $this->ttl($rrsetData['ttl']);
        $path = $this->rrsetPath($domainName, $subname, $type);
        $body = $this->request('GET', $path);
        $rrset = $body['rrset'] ?? [];
        $records = $rrset['records'] ?? [];
        if (!$records) {
            throw new \RuntimeException('Hetzner RRSet contains no records.');
        }
        $oldValue = $rrsetData['old_value'] ?? null;
        if ($oldValue === null) {
            if (count($records) !== 1) {
                throw new \InvalidArgumentException('old_value is required when updating a multi-value Hetzner RRSet.');
            }
            $oldValue = $records[0]['value'];
        }
        $oldValue = $this->recordValue($type, $oldValue);
        $newValue = $this->recordValue($type, $rrsetData['records'][0], $rrsetData);
        $found = false;
        foreach ($records as &$record) {
            if ($this->recordValue($type, $record['value']) === $oldValue) {
                $record['value'] = $newValue;
                $found = true;
            }
        }
        unset($record);
        if (!$found) {
            throw new \RuntimeException('The old record value was not found in the Hetzner RRSet.');
        }
        if (count(array_unique(array_column($records, 'value'))) !== count($records)) {
            throw new \InvalidArgumentException('The new record value already exists in the Hetzner RRSet.');
        }
        if ($oldValue !== $newValue) {
            // Preserve sibling values and comments when replacing the selected value.
            $this->waitForAction($this->request('POST', $path . '/actions/set_records', ['json' => ['records' => $records]]));
        }
        if (($rrset['ttl'] ?? null) !== $ttl) {
            $this->waitForAction($this->request('POST', $path . '/actions/change_ttl', ['json' => ['ttl' => $ttl]]));
        }
        return true;
    }

    public function modifyBulkRRsets($domainName, $rrsetDataArray) {
        throw new \Exception("Not yet implemented");
    }

    public function deleteRRset($domainName, $subname, $type, $value, $persistedRecordId = null) {
        if ($value === null) {
            throw new \InvalidArgumentException('A record value is required for deletion.');
        }
        $path = $this->rrsetPath($domainName, $subname, $type);
        // Removes only this value; Hetzner removes an empty RRSet automatically.
        $this->waitForAction($this->request('POST', $path . '/actions/remove_records', ['json' => [
            'records' => [['value' => $this->recordValue($type, $value)]],
        ]]));
        return true;
    }

    public function deleteBulkRRsets($domainName, $rrsetDataArray) {
        throw new \exception("Not yet implemented");
    }

    public function enableDNSSEC(string $domainName): array
    {
        throw new \Exception("DNSSEC activation is not supported by this DNS provider.");
    }

    public function disableDNSSEC(string $domainName): bool
    {
        throw new \Exception("DNSSEC deactivation is not supported by this DNS provider.");
    }

    public function getDNSSECStatus(string $domainName): array
    {
        throw new \Exception("DNSSEC status lookup is not supported by this DNS provider.");
    }

    public function getDSRecords(string $domainName): array
    {
        throw new \Exception("Retrieving DS records is not supported by this DNS provider.");
    }

    private function request(string $method, string $path, array $options = []): array {
        $options['headers'] = $this->headers;
        $response = $this->client->request($method, $path, $options);
        $body = json_decode($response->getBody()->getContents(), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($body)) {
            throw new \RuntimeException('Invalid Hetzner API response.');
        }
        return $body;
    }

    private function waitForAction(array $body): void {
        $action = $body['action'] ?? null;
        $deadline = microtime(true) + 60;
        while (is_array($action)) {
            if (($action['status'] ?? null) === 'success') {
                return;
            }
            if (($action['status'] ?? null) === 'error') {
                throw new \RuntimeException('Hetzner action failed: ' . ($action['error']['message'] ?? 'unknown error'));
            }
            if (($action['status'] ?? null) !== 'running' || empty($action['id'])) {
                break;
            }
            if (microtime(true) >= $deadline) {
                throw new \RuntimeException('Timed out waiting for Hetzner action ' . $action['id']);
            }
            usleep(250000);
            $body = $this->request('GET', 'zones/actions/' . rawurlencode((string)$action['id']));
            $action = $body['action'] ?? null;
        }
        throw new \RuntimeException('Hetzner API did not return a valid action.');
    }

    private function domainName(string $domainName): string {
        $domainName = strtolower(rtrim(trim($domainName), '.'));
        if ($domainName === '' || !filter_var($domainName, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME)) {
            throw new \InvalidArgumentException('A valid ASCII/punycode domain name is required.');
        }
        return $domainName;
    }

    private function zonePath(string $domainName): string {
        // Address migrated zones by name; old DNS Console IDs are not Cloud IDs.
        return 'zones/' . rawurlencode($this->domainName($domainName));
    }

    private function rrsetPath(string $domainName, string $subname, string $type): string {
        $subname = strtolower(rtrim(trim($subname), '.'));
        $subname = $subname === '' ? '@' : $subname;
        $type = strtoupper($type);
        if (!preg_match('/^[A-Z][A-Z0-9]*$/', $type)) {
            throw new \InvalidArgumentException('Invalid record type.');
        }
        return $this->zonePath($domainName) . '/rrsets/' . rawurlencode($subname) . '/' . $type;
    }

    private function ttl($ttl): int {
        $ttl = filter_var($ttl, FILTER_VALIDATE_INT);
        if ($ttl === false || $ttl < 60 || $ttl > 2147483647) {
            throw new \InvalidArgumentException('Hetzner TTL must be between 60 and 2147483647 seconds.');
        }
        return $ttl;
    }

    private function recordValue(string $type, string $value, array $data = []): string {
        $type = strtoupper($type);
        if ($type === 'TXT') {
            $txt = new TXT();
            if (str_starts_with($value, '"') && str_ends_with($value, '"')) {
                $txt->fromText($value);
            } else {
                $txt->setText($value, true);
            }
            return $txt->toText() ?: '""';
        }
        $value = trim($value);
        if ($type === 'MX') {
            if (!preg_match('/^\d+\s+/', $value)) {
                if (!isset($data['priority'])) {
                    throw new \InvalidArgumentException('MX value must include its priority.');
                }
                $value = (int)$data['priority'] . ' ' . $value;
            }
            if (!preg_match('/^(\d+)\s+(\S+)$/', $value, $parts)) {
                throw new \InvalidArgumentException('Invalid MX value.');
            }
            return (int)$parts[1] . ' ' . strtolower(rtrim($parts[2], '.')) . '.';
        }
        if ($type === 'SRV') {
            if (!preg_match('/^\d+\s+\d+\s+\d+\s+/', $value)) {
                if (!isset($data['priority'], $data['weight'], $data['port'])) {
                    throw new \InvalidArgumentException('SRV requires priority, weight, port and target.');
                }
                $value = (int)$data['priority'] . ' ' . (int)$data['weight'] . ' ' . (int)$data['port'] . ' ' . $value;
            }
            if (!preg_match('/^(\d+)\s+(\d+)\s+(\d+)\s+(\S+)$/', $value, $parts)) {
                throw new \InvalidArgumentException('Invalid SRV value.');
            }
            return (int)$parts[1] . ' ' . (int)$parts[2] . ' ' . (int)$parts[3] . ' ' . strtolower(rtrim($parts[4], '.')) . '.';
        }
        if (in_array($type, ['CNAME', 'NS', 'PTR'], true)) {
            return strtolower(rtrim($value, '.')) . '.';
        }
        if (in_array($type, ['A', 'AAAA'], true) && filter_var($value, FILTER_VALIDATE_IP)) {
            return inet_ntop(inet_pton($value));
        }
        return $value;
    }
}
