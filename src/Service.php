<?php

namespace DNS;

use PDO;
use Exception;

class Service
{
    protected ?PDO $db = null;
    private $dnsProvider;

    /**
     * Constructor to initialize the database connection
     *
     * @param PDO $db
     */
    public function __construct(PDO $db)
    {
        $this->db = $db;
    }

    /**
     * Choose the DNS provider based on the given configuration
     *
     * @param array $config Configuration containing provider details
     * @throws Exception if the provider is unknown
     */
    private function chooseDnsProvider(array $config)
    {
        $providerName = $config['provider'];

        switch ($providerName) {
            case 'Bind':
                $this->dnsProvider = new Providers\Bind($config);
                break;
            case 'Desec':
                $this->dnsProvider = new Providers\Desec($config);
                break;
            case 'DNSimple':
                $this->dnsProvider = new Providers\DNSimple($config);
                break;
            case 'Hetzner':
                $this->dnsProvider = new Providers\Hetzner($config);
                break;
            case 'PowerDNS':
                $this->dnsProvider = new Providers\PowerDNS($config);
                break;
            case 'Vultr':
                $this->dnsProvider = new Providers\Vultr($config);
                break;
            default:
                throw new Exception("Unknown DNS provider: {$providerName}");
        }
    }

    /**
     * Example method to fetch data securely using PDO
     *
     * @param string $query SQL query string with placeholders
     * @param array $params Parameters for the query
     * @return array|null Fetched data or null if no data found
     */
    public function fetchData(string $query, array $params = []): ?array
    {
        $stmt = $this->db->prepare($query);
        if ($stmt->execute($params)) {
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: null;
        }
        return null;
    }

    /**
     * Example method to execute a query securely using PDO
     *
     * @param string $query SQL query string with placeholders
     * @param array $params Parameters for the query
     * @return bool True if the query executed successfully, otherwise false
     */
    public function executeQuery(string $query, array $params = []): bool
    {
        $stmt = $this->db->prepare($query);
        return $stmt->execute($params);
    }

    public function createDomain(array $order): array
    {
        // Extract domain configuration
        $config = json_decode($order['config'], true);
        if (!$config || !isset($config['domain_name'])) {
            throw new Exception("Invalid configuration: domain name missing.");
        }

        $domainName = $config['domain_name'];
        $clientId = $order['client_id'] ?? null;

        if (!$clientId) {
            throw new Exception("Client ID is missing.");
        }

        // Set up DNS provider
        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 1: Create domain in DNS
        try {
            $this->dnsProvider->createDomain($domainName);
        } catch (Exception $e) {
            throw new Exception("Failed to create domain in DNS: " . $e->getMessage());
        }

        // Step 2: Save domain in the database
        $now = date('Y-m-d H:i:s');

        $query = "
            INSERT INTO service_dns (client_id, config, domain_name, created_at, updated_at)
            VALUES (:client_id, :config, :domain_name, :created_at, :updated_at)
            ON DUPLICATE KEY UPDATE
                config = VALUES(config),
                updated_at = VALUES(updated_at)
        ";

        $params = [
            ':client_id' => $clientId,
            ':config' => json_encode($config),
            ':domain_name' => $domainName,
            ':created_at' => $now,
            ':updated_at' => $now,
        ];

        try {
            $this->executeQuery($query, $params);
        } catch (Exception $e) {
            throw new Exception("Failed to save domain in the database: " . $e->getMessage());
        }

        return [
            'status' => 'success',
            'message' => 'Domain successfully created in DNS and database.',
            'domain_name' => $domainName,
        ];
    }

    public function deleteDomain(array $order): void
    {
        // Validate order configuration
        $config = json_decode($order['config'], true);
        if (!$config || !isset($config['domain_name'])) {
            throw new Exception("Invalid configuration: domain name missing.");
        }

        $domainName = $config['domain_name'];

        // Set up DNS provider
        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 1: Delete domain in DNS
        try {
            $this->dnsProvider->deleteDomain($domainName);
        } catch (Exception $e) {
            if (strpos($e->getMessage(), 'Not Found') !== false) {
                // Log and continue if domain does not exist
                error_log("Domain $domainName not found in DNS provider, but proceeding with deletion.");
            } else {
                // Rethrow other exceptions
                throw new Exception("Failed to delete domain $domainName in DNS: " . $e->getMessage());
            }
        }

        // Step 2: Delete domain from database
        $query = "DELETE FROM service_dns WHERE domain_name = :domain_name";
        $params = [':domain_name' => $domainName];

        try {
            $this->executeQuery($query, $params);
        } catch (Exception $e) {
            throw new Exception("Failed to delete domain $domainName from the database: " . $e->getMessage());
        }
    }

    /**
     * Adds a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for adding a DNS record.
     * @return bool Returns true on successful addition of the DNS record.
     * @throws Exception If the domain does not exist or an error occurs.
     */
    public function addRecord(array $data): bool
    {
        // Step 1: Validate input and fetch domain details
        if (empty($data['domain_name'])) {
            throw new Exception("Domain name is missing.");
        }

        $domainName = $data['domain_name'];
        $query = "SELECT * FROM service_dns WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        // Step 2: Set up the DNS provider
        $config = json_decode($domain[0]['config'], true);
        $this->chooseDnsProvider($config);

        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 3: Prepare RRset data for DNS provider
        $rrsetData = [
            'subname' => $data['record_name'],
            'type' => $data['record_type'],
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']],
        ];

        // Special handling for MX records
        if ($data['record_type'] === 'MX') {
            if ($config['provider'] === 'Desec') {
                $rrsetData['records'] = [$data['record_priority'] . ' ' . $data['record_value']];
            } else {
                $rrsetData['priority'] = $data['record_priority'];
            }
        }

        // Step 4: Create record in DNS provider
        try {
            $this->dnsProvider->createRRset($domainName, $rrsetData);
        } catch (Exception $e) {
            throw new Exception("Failed to add DNS record: " . $e->getMessage());
        }

        // Step 5: Add record to the database
        $insertQuery = "
            INSERT INTO service_dns_records (domain_id, type, host, value, ttl, priority, created_at, updated_at)
            VALUES (:domain_id, :type, :host, :value, :ttl, :priority, :created_at, :updated_at)
        ";
        $params = [
            ':domain_id' => $domain[0]['id'],
            ':type' => $data['record_type'],
            ':host' => $data['record_name'],
            ':value' => $data['record_value'],
            ':ttl' => (int) $data['record_ttl'],
            ':priority' => isset($data['record_priority']) ? (int) $data['record_priority'] : 0,
            ':created_at' => date('Y-m-d H:i:s'),
            ':updated_at' => date('Y-m-d H:i:s'),
        ];

        try {
            $stmt = $this->db->prepare($insertQuery);
            $stmt->execute($params);
            return (int) $this->db->lastInsertId();
        } catch (Exception $e) {
            throw new Exception("Failed to add DNS record to the database: " . $e->getMessage());
        }
    }
    
    /**
     * Updates a DNS record for a specified domain.
     *
     * @param array $data An array containing the necessary information for updating a DNS record.
     * @return bool Returns true on successful update of the DNS record.
     * @throws Exception If the domain or record does not exist or an error occurs.
     */
    public function updateRecord(array $data): bool
    {
        // Validate the input
        if (empty($data['domain_name']) || empty($data['record_id'])) {
            throw new Exception("Domain name or record ID is missing.");
        }

        $domainName = $data['domain_name'];
        $recordId = $data['record_id'];

        // Step 1: Fetch the domain configuration
        $query = "SELECT * FROM service_dns WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        $config = json_decode($domain[0]['config'], true);

        // Step 2: Set up the DNS provider
        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 3: Prepare the RRset data for the DNS provider
        $rrsetData = [
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']],
        ];

        if ($data['record_type'] === 'MX') {
            if ($config['provider'] === 'Desec') {
                $rrsetData['records'] = [$data['record_priority'] . ' ' . $data['record_value']];
            } else {
                $rrsetData['priority'] = $data['record_priority'];
            }
        }

        // Step 4: Update the record in the DNS provider
        try {
            $this->dnsProvider->modifyRRset($domainName, $data['record_name'], $data['record_type'], $rrsetData);
        } catch (Exception $e) {
            throw new Exception("Failed to update DNS record: " . $e->getMessage());
        }

        // Step 5: Update the record in the database
        $updateQuery = "
            UPDATE service_dns_records
            SET ttl = :ttl, value = :value, updated_at = :updated_at
            WHERE id = :record_id AND domain_id = :domain_id
        ";
        $updateParams = [
            ':ttl' => (int) $data['record_ttl'],
            ':value' => $data['record_value'],
            ':updated_at' => date('Y-m-d H:i:s'),
            ':record_id' => $recordId,
            ':domain_id' => $domain[0]['id'],
        ];

        try {
            $this->executeQuery($updateQuery, $updateParams);
        } catch (Exception $e) {
            throw new Exception("Failed to update DNS record in the database: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Deletes a DNS record for a specified domain.
     *
     * @param array $data An array containing the identification information of the DNS record to be deleted.
     * @return bool Returns true if the DNS record was successfully deleted.
     * @throws Exception If the domain or record does not exist or an error occurs.
     */
    public function delRecord(array $data): bool
    {
        // Validate the input
        if (empty($data['domain_name']) || empty($data['record_id'])) {
            throw new Exception("Domain name or record ID is missing.");
        }

        $domainName = $data['domain_name'];
        $recordId = $data['record_id'];

        // Step 1: Fetch the domain configuration
        $query = "SELECT * FROM service_dns WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        $config = json_decode($domain[0]['config'], true);

        // Step 2: Set up the DNS provider
        $this->chooseDnsProvider($config);
        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 3: Delete the record from the DNS provider
        try {
            $this->dnsProvider->deleteRRset($domainName, $data['record_name'], $data['record_type'], $data['record_value']);
        } catch (Exception $e) {
            throw new Exception("Failed to delete DNS record from the provider: " . $e->getMessage());
        }

        // Step 4: Delete the record from the database
        $deleteQuery = "
            DELETE FROM service_dns_records
            WHERE id = :record_id AND domain_id = :domain_id
        ";
        $deleteParams = [
            ':record_id' => $recordId,
            ':domain_id' => $domain[0]['id'],
        ];

        try {
            $this->executeQuery($deleteQuery, $deleteParams);
        } catch (Exception $e) {
            throw new Exception("Failed to delete DNS record from the database: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Creates the database structure to store the DNS records in.
     *
     * @return bool Returns true on successful installation.
     * @throws Exception If an error occurs during table creation.
     */
    public function install(): bool
    {
        $sqlServiceDns = '
            CREATE TABLE IF NOT EXISTS `service_dns` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT UNIQUE,
                `client_id` BIGINT(20) NOT NULL,
                `domain_name` VARCHAR(75),
                `provider_id` VARCHAR(11),
                `zoneId` VARCHAR(100) DEFAULT NULL,
                `config` TEXT NOT NULL,
                `created_at` DATETIME,
                `updated_at` DATETIME,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';

        $sqlServiceDnsRecords = '
            CREATE TABLE IF NOT EXISTS `service_dns_records` (
                `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                `domain_id` BIGINT(20) NOT NULL,
                `recordId` VARCHAR(100) DEFAULT NULL,
                `type` VARCHAR(10) NOT NULL,
                `host` VARCHAR(255) NOT NULL,
                `value` TEXT NOT NULL,
                `ttl` INT(11) DEFAULT NULL,
                `priority` INT(11) DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                FOREIGN KEY (`domain_id`) REFERENCES `service_dns`(`id`) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8 AUTO_INCREMENT=1;
        ';

        try {
            $this->db->exec($sqlServiceDns);
            $this->db->exec($sqlServiceDnsRecords);
        } catch (Exception $e) {
            throw new Exception("Failed to install database structure: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Removes the DNS records from the database.
     *
     * @return bool Returns true on successful uninstallation.
     * @throws Exception If an error occurs during table deletion.
     */
    public function uninstall(): bool
    {
        $sqlDropServiceDnsRecords = 'DROP TABLE IF EXISTS `service_dns_records`';
        $sqlDropServiceDns = 'DROP TABLE IF EXISTS `service_dns`';

        try {
            $this->db->exec($sqlDropServiceDnsRecords);
            $this->db->exec($sqlDropServiceDns);
        } catch (Exception $e) {
            throw new Exception("Failed to uninstall database structure: " . $e->getMessage());
        }

        return true;
    }

    /**
     * Fetches domain information from the DNS provider, compares it with the database,
     * and updates the database if discrepancies are found.
     *
     * @throws Exception If an error occurs during the cron job.
     */
    public function onCronRun(): void
    {
        // Step 1: Check if the servicedns module is activated
        $sqlCheck = 'SELECT COUNT(*) FROM extension WHERE name = :name AND status = :status';
        $paramsCheck = [
            ':name' => 'servicedns',
            ':status' => 'installed',
        ];

        $isActivated = (bool) $this->fetchData($sqlCheck, $paramsCheck)[0]['COUNT(*)'];
        if (!$isActivated) {
            throw new \Exception("The 'servicedns' module is not activated.");
        }

        // Step 2: Fetch the DNS provider configuration
        $sqlFetchProduct = 'SELECT config FROM product WHERE title = :title LIMIT 1';
        $paramsFetchProduct = [
            ':title' => 'DNS hosting',
        ];

        $productRow = $this->fetchData($sqlFetchProduct, $paramsFetchProduct);
        if (!$productRow) {
            throw new \Exception("Product with title 'DNS hosting' not found.");
        }

        $configArray = json_decode($productRow[0]['config'], true);
        $this->chooseDnsProvider($configArray);

        if ($this->dnsProvider === null) {
            throw new \Exception("DNS provider is not set.");
        }

        // Step 3: Fetch all domains from the database
        $sqlFetchDomains = 'SELECT * FROM service_dns';
        $domains = $this->fetchData($sqlFetchDomains);

        foreach ($domains as $domain) {
            $domainName = $domain['domain_name'];
            $dbConfig = json_decode($domain['config'], true);

            try {
                // Step 4: Fetch domain configuration from the provider
                $providerConfig = $this->dnsProvider->getDomain($domainName); // Assuming getDomain returns JSON
                $providerConfigArray = json_decode($providerConfig, true);

                // Step 5: Compare configurations and update the database if needed
                if ($providerConfigArray !== $dbConfig) {
                    echo "Updating configuration for domain '{$domainName}'...\n";

                    $updateQuery = "
                        UPDATE service_dns
                        SET config = :config, updated_at = :updated_at
                        WHERE id = :id
                    ";
                    $updateParams = [
                        ':config' => json_encode($providerConfigArray),
                        ':updated_at' => date('Y-m-d H:i:s'),
                        ':id' => $domain['id'],
                    ];

                    $this->executeQuery($updateQuery, $updateParams);
                    echo "Domain '{$domainName}' updated successfully.\n";
                } else {
                    echo "Domain '{$domainName}' is already up-to-date.\n";
                }
            } catch (\Exception $e) {
                echo "Failed to process domain '{$domainName}': " . $e->getMessage() . "\n";
            }
        }
    }

}
