<?php

namespace PlexDNS;

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
            case 'AnycastDNS':
                $this->dnsProvider = new Providers\AnycastDNS($config);
                break;
            case 'Bind':
                $this->dnsProvider = new Providers\Bind($config);
                break;
            case 'Cloudflare':
                $this->dnsProvider = new Providers\Cloudflare($config);
                break;
            case 'ClouDNS':
                $this->dnsProvider = new Providers\ClouDNS($config);
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

        $dbDriver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($dbDriver === 'mysql') {
            $query = "
                INSERT INTO zones (client_id, config, domain_name, created_at, updated_at)
                VALUES (:client_id, :config, :domain_name, :created_at, :updated_at)
                ON DUPLICATE KEY UPDATE
                    config = VALUES(config),
                    updated_at = VALUES(updated_at)
            ";
        } elseif ($dbDriver === 'pgsql') {
            $query = "
                INSERT INTO zones (client_id, config, domain_name, created_at, updated_at)
                VALUES (:client_id, :config, :domain_name, :created_at, :updated_at)
                ON CONFLICT (domain_name) DO UPDATE 
                SET config = EXCLUDED.config, updated_at = EXCLUDED.updated_at
            ";
        } elseif ($dbDriver === 'sqlite') {
            $query = "
                INSERT INTO zones (client_id, config, domain_name, created_at, updated_at)
                VALUES (:client_id, :config, :domain_name, :created_at, :updated_at)
                ON CONFLICT(domain_name) DO UPDATE SET
                    config = excluded.config,
                    updated_at = excluded.updated_at
            ";
        } else {
            throw new Exception("Unsupported database type: $dbDriver");
        }

        $params = [
            ':client_id' => $clientId,
            ':config' => json_encode(['provider' => $config['provider'] ?? null]),
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
        $query = "SELECT id FROM zones WHERE domain_name = :domain_name";
        $params = [':domain_name' => $domainName];

        try {
            $this->db->beginTransaction(); 

            $result = $this->fetchData($query, $params);
            $domainId = $result[0]['id'] ?? null;

            if (!$domainId) {
                throw new Exception("Domain $domainName not found in the database.");
            }

            $query = "DELETE FROM records WHERE domain_id = :domain_id";
            $params = [':domain_id' => $domainId];
            $this->executeQuery($query, $params);

            $query = "DELETE FROM zones WHERE id = :domain_id";
            $params = [':domain_id' => $domainId];
            $this->executeQuery($query, $params);

            $this->db->commit(); 

        } catch (Exception $e) {
            $this->db->rollback();
            throw new Exception("Failed to delete domain $domainName and its records: " . $e->getMessage());
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
        // Validate data configuration
        if (!$data || !isset($data['domain_name'])) {
            throw new Exception("Invalid configuration: domain name missing.");
        }

        $domainName = $data['domain_name'];
        $query = "SELECT * FROM zones WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        $this->chooseDnsProvider($data);
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
            if ($data['provider'] === 'Desec') {
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
            INSERT INTO records (domain_id, type, host, value, ttl, priority, created_at, updated_at, recordId)
            VALUES (:domain_id, :type, :host, :value, :ttl, :priority, :created_at, :updated_at, :record_id)
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
	    //':record_id' => $data['record_id'],
	    ':record_id' => uniqid(), // Create a unique value

	];

	//die(var_dump($params));

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
        if (empty($data['domain_name']) || !array_key_exists('record_id', $data)) {
            throw new Exception("Domain name or record ID is missing.");
        }

        $domainName = $data['domain_name'];
	$recordId = $data['record_id'];

        // Step 1: Fetch the domain configuration
        $query = "SELECT * FROM zones WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        // Step 2: Set up the DNS provider
        $this->chooseDnsProvider($data);
        if ($this->dnsProvider === null) {
            throw new Exception("DNS provider is not set.");
        }

        // Step 3: Prepare the RRset data for the DNS provider
        $rrsetData = [
            'ttl' => (int) $data['record_ttl'],
            'records' => [$data['record_value']],
        ];

        if ($data['record_type'] === 'MX') {
            if ($data['provider'] === 'Desec') {
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
            UPDATE records
            SET ttl = :ttl, value = :value, updated_at = :updated_at
            WHERE recordId = :record_id AND domain_id = :domain_id
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
            
            $zoneUpdateQuery = "
                UPDATE zones
                SET updated_at = :updated_at
                WHERE id = :domain_id
            ";
            $zoneUpdateParams = [
                ':updated_at' => date('Y-m-d H:i:s'),
                ':domain_id' => $domain[0]['id'],
            ];

            $this->executeQuery($zoneUpdateQuery, $zoneUpdateParams);
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
        if (empty($data['domain_name']) || !array_key_exists('record_id', $data)) {
            throw new Exception("Domain name or record ID is missing.");
        }

        $domainName = $data['domain_name'];
        $recordId = $data['record_id'];

        // Step 1: Fetch the domain configuration
        $query = "SELECT * FROM zones WHERE domain_name = :domain_name";
        $domain = $this->fetchData($query, [':domain_name' => $domainName]);

        if (!$domain) {
            throw new Exception("Domain does not exist.");
        }

        // Step 2: Set up the DNS provider
        $this->chooseDnsProvider($data);
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
            DELETE FROM records
            WHERE recordId = :record_id AND domain_id = :domain_id
        ";
        $deleteParams = [
            ':record_id' => $recordId,
            ':domain_id' => $domain[0]['id'],
        ];

        try {
            $this->executeQuery($deleteQuery, $deleteParams);
            
            $zoneUpdateQuery = "
                UPDATE zones
                SET updated_at = :updated_at
                WHERE id = :domain_id
            ";
            $zoneUpdateParams = [
                ':updated_at' => date('Y-m-d H:i:s'),
                ':domain_id' => $domain[0]['id'],
            ];

            $this->executeQuery($zoneUpdateQuery, $zoneUpdateParams);
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
        // Detect database type
        $dbDriver = $this->db->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($dbDriver === 'mysql') {
            $sqlZones = '
                CREATE TABLE IF NOT EXISTS `zones` (
                    `id` BIGINT(20) NOT NULL AUTO_INCREMENT,
                    `client_id` BIGINT(20) NOT NULL,
                    `domain_name` VARCHAR(75),
                    `provider_id` VARCHAR(11),
                    `zoneId` VARCHAR(100) DEFAULT NULL,
                    `config` TEXT NOT NULL,
                    `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                    `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    PRIMARY KEY (`id`)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ';

            $sqlRecords = '
                CREATE TABLE IF NOT EXISTS `records` (
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
                    FOREIGN KEY (`domain_id`) REFERENCES `zones`(`id`) ON DELETE CASCADE
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
            ';
        } elseif ($dbDriver === 'pgsql') {
            $sqlZones = '
                CREATE TABLE IF NOT EXISTS zones (
                    id BIGSERIAL PRIMARY KEY,
                    client_id BIGINT NOT NULL,
                    domain_name VARCHAR(75),
                    provider_id VARCHAR(11),
                    zoneId VARCHAR(100) DEFAULT NULL,
                    config TEXT NOT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                );
            ';

            $sqlRecords = '
                CREATE TABLE IF NOT EXISTS records (
                    id BIGSERIAL PRIMARY KEY,
                    domain_id BIGINT NOT NULL,
                    recordId VARCHAR(100) DEFAULT NULL,
                    type VARCHAR(10) NOT NULL,
                    host VARCHAR(255) NOT NULL,
                    value TEXT NOT NULL,
                    ttl INTEGER DEFAULT NULL,
                    priority INTEGER DEFAULT NULL,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (domain_id) REFERENCES zones(id) ON DELETE CASCADE
                );
            ';
        } elseif ($dbDriver === 'sqlite') {
            $sqlZones = '
                CREATE TABLE IF NOT EXISTS zones (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    client_id INTEGER NOT NULL,
                    domain_name TEXT UNIQUE NOT NULL,
                    provider_id TEXT,
                    zoneId TEXT DEFAULT NULL,
                    config TEXT NOT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
                );
            ';

            $sqlRecords = '
                CREATE TABLE IF NOT EXISTS records (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    domain_id INTEGER NOT NULL,
                    recordId TEXT DEFAULT NULL,
                    type TEXT NOT NULL,
                    host TEXT NOT NULL,
                    value TEXT NOT NULL,
                    ttl INTEGER DEFAULT NULL,
                    priority INTEGER DEFAULT NULL,
                    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
                    FOREIGN KEY (domain_id) REFERENCES zones(id) ON DELETE CASCADE
                );
            ';

            // Enable foreign keys for SQLite
            $this->db->exec("PRAGMA foreign_keys = ON;");
        } else {
            throw new Exception("Unsupported database driver: " . $dbDriver);
        }

        try {
            $this->db->exec($sqlZones);
            $this->db->exec($sqlRecords);
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
        $sqlRecords = 'DROP TABLE IF EXISTS `records`';
        $sqlZones = 'DROP TABLE IF EXISTS `zones`';

        try {
            $this->db->exec($sqlRecords);
            $this->db->exec($sqlZones);
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
        $configArray = null;
        $this->chooseDnsProvider($configArray);

        if ($this->dnsProvider === null) {
            throw new \Exception("DNS provider is not set.");
        }

        // Step 1: Fetch all domains from the database
        $sqlFetchDomains = 'SELECT * FROM zones';
        $domains = $this->fetchData($sqlFetchDomains);

        foreach ($domains as $domain) {
            $domainName = $domain['domain_name'];
            $dbConfig = json_decode($domain['config'], true);

            try {
                // Step 2: Fetch domain configuration from the provider
                $providerConfig = $this->dnsProvider->getDomain($domainName);
                $providerConfigArray = json_decode($providerConfig, true);

                // Step 3: Compare configurations and update the database if needed
                if ($providerConfigArray !== $dbConfig) {
                    echo "Updating configuration for domain '{$domainName}'...\n";

                    $updateQuery = "
                        UPDATE zones
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
