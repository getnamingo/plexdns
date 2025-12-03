<?php

use Dotenv\Dotenv;
use PlexDNS\Service;

require_once 'vendor/autoload.php';

function getProviderCredentials(string $provider): ?array {
    // Convert provider name to uppercase (matches env variables)
    $providerKey = strtoupper(str_replace(' ', '_', $provider));

    // Get all environment variables
    $envVars = $_ENV;

    // Find all keys related to this provider (keys start with "DNS_{PROVIDER}_")
    $credentials = [];
    foreach ($envVars as $key => $value) {
        if (strpos($key, "DNS_{$providerKey}_") === 0 && !empty($value)) {
            // Extract the field name after "DNS_{PROVIDER}_"
            $field = str_replace("DNS_{$providerKey}_", '', $key);
            $credentials[$field] = $value;
        }
    }

    // Return credentials only if they have values, otherwise return null
    return !empty($credentials) ? $credentials : null;
}

function getActiveProviders(): array {
    $activeProviders = [];
    
    foreach ($_ENV as $key => $value) {
        if (strpos($key, 'DNS_') === 0 && !empty($value)) {
            // Extract provider name (between "DNS_" and "_FIELDNAME")
            preg_match('/DNS_([^_]+)_/', $key, $matches);
            if (!empty($matches[1])) {
                $providerName = $matches[1];
                
                // Add provider only if it hasn't been added already
                if (!isset($activeProviders[$providerName])) {
                    $activeProviders[$providerName] = str_replace('_', ' ', ucfirst(strtolower($providerName)));
                }
            }
        }
    }

    return $activeProviders;
}

function getProviderDisplayName(string $provider): string {
    $providerNames = [
        'ANYCASTDNS'  => 'AnycastDNS',
        'BIND9'       => 'Bind',
        'CLOUDFLARE'  => 'Cloudflare',
        'CLOUDNS'     => 'ClouDNS',
        'DESEC'       => 'Desec',
        'DNSIMPLE'    => 'DNSimple',
        'HETZNER'     => 'Hetzner',
        'POWERDNS'    => 'PowerDNS',
        'VULTR'       => 'Vultr',
    ];

    return $providerNames[strtoupper($provider)] ?? ucfirst(strtolower($provider));
}

// Load environment variables
$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->load();

// Get provider
$provider = 'Desec' ?? null;

if (!$provider) {
    die("Error: Missing required environment variables in .env file (PROVIDER)\n");
}

try {
    $credentials = getProviderCredentials($provider);
    $providerDisplay = getProviderDisplayName($provider);

    $apiKey = $credentials['API_KEY'] ?? null;
    $bindip = $credentials['BIND_IP'] ?? '127.0.0.1';
    $powerdnsip = $credentials['POWERDNS_IP'] ?? '127.0.0.1';
    $cloudnsAuthId = $credentials['AUTH_ID'] ?? null;
    $cloudnsAuthPassword = $credentials['AUTH_PASSWORD'] ?? null;

    if (!$apiKey) {
        throw new Exception("Missing API Key for provider: $provider");
    }
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}

// Database configuration
$dbType = $_ENV['DB_TYPE'] ?? 'mysql'; // Default to MySQL
$host = $_ENV['DB_HOST'] ?? '127.0.0.1';
$dbName = $_ENV['DB_NAME'] ?? '';
$username = $_ENV['DB_USER'] ?? '';
$password = $_ENV['DB_PASS'] ?? '';
$sqlitePath = __DIR__ . '/database.sqlite';

if ($dbType !== 'sqlite' && (!$dbName || !$username || !$password)) {
    die("Error: Missing required database configuration in .env file\n");
}

$logFilePath = '/var/log/plexdns/plexdns.log';
$log = setupPlexLogger($logFilePath, 'PlexDNS');
$log->info('job started.');

try {
    if ($dbType === 'mysql') {
        $dsn = "mysql:host=$host;dbname=$dbName;charset=utf8mb4";
    } elseif ($dbType === 'pgsql') {
        $dsn = "pgsql:host=$host;dbname=$dbName";
    } elseif ($dbType === 'sqlite') {
        $dsn = "sqlite:$sqlitePath";
    } else {
        throw new Exception("Unsupported database type: $dbType");
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
    ]);

    if ($dbType === 'sqlite') {
        $pdo->exec("PRAGMA foreign_keys = ON;"); // Enable foreign key constraints for SQLite
    }

    // Initialize the Service with the database connection
    $service = new Service($pdo);

    // Step 1: Install database structure
    echo "Installing database structure...\n";
    $service->install();

    // Step 2: Create a domain
    echo "Creating a domain...\n";
    $domainOrder = [
        'client_id' => 1,
        'config' => json_encode(['domain_name' => 'example.com', 'provider' => $providerDisplay, 'apikey' => $apiKey]),
    ];
    $domain = $service->createDomain($domainOrder);
    print_r($domain);

    // Step 2b: DNSSEC operations
    $dnssecConfig = [
        'provider'    => $providerDisplay,
        'domain_name' => 'example.com',
        'apikey'      => $apiKey,
        // add provider-specific fields here if needed:
        // 'powerdnsip'           => $powerdnsip,
        // 'cloudns_auth_id'      => $cloudnsAuthId,
        // 'cloudns_auth_password'=> $cloudnsAuthPassword,
    ];

    echo "Enabling DNSSEC...\n";
    $ds = $service->enableDNSSEC($dnssecConfig);
    echo "DS records after enable:\n";
    print_r($ds);

    echo "Getting DNSSEC status...\n";
    $status = $service->getDNSSECStatus($dnssecConfig);
    print_r($status);

    echo "Getting DS records only...\n";
    $dsOnly = $service->getDSRecords($dnssecConfig);
    print_r($dsOnly);

    // Optional: disable DNSSEC if the provider supports it
    echo "Disabling DNSSEC (if supported)...\n";
    try {
        $service->disableDNSSEC($dnssecConfig);
        echo "DNSSEC disabled.\n";
    } catch (Exception $e) {
        echo "Disable DNSSEC not supported: " . $e->getMessage() . "\n";
    }

    // Step 3: Add a DNS record
    echo "Adding a DNS record...\n";
    $recordData = [
        'domain_name' => 'example.com',
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.168.1.1',
        'record_ttl' => 3600,
        'record_priority' => 10, // Optional
        'provider' => $providerDisplay,
        'apikey' => $apiKey
    ];
    $recordId = $service->addRecord($recordData);
    echo "DNS record added successfully.\n";

    // Step 4: Update a DNS record
    echo "Updating a DNS record...\n";
    $updateData = [
        'domain_name' => 'example.com',
        'record_id' => $recordId,
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.168.1.2',
        'record_ttl' => 7200,
        'provider' => $providerDisplay,
        'apikey' => $apiKey
    ];
    $service->updateRecord($updateData);
    echo "DNS record updated successfully.\n";

     // Step 5: Delete a DNS record
    echo "Deleting a DNS record...\n";
    $deleteData = [
        'domain_name' => 'example.com',
        'record_id' => $recordId,
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.168.1.2',
        'provider' => $providerDisplay,
        'apikey' => $apiKey
    ];
    $service->delRecord($deleteData);
    echo "DNS record deleted successfully.\n";

    // Step 6: Delete a domain
    echo "Deleting a domain...\n";
    $service->deleteDomain(['config' => json_encode(['domain_name' => 'example.com', 'provider' => $providerDisplay, 'apikey' => $apiKey])]);
    echo "Domain deleted successfully.\n";

    // Step 7: Uninstall database structure
    echo "Uninstalling database structure...\n";
    $service->uninstall();
    
    $log->info('job finished successfully.');

} catch (Exception $e) {
    $log->error('Error: ' . $e->getMessage());
} catch (PDOException $e) {
    $log->error('Database error: ' . $e->getMessage());
} catch (Throwable $e) {
    $log->error('Error: ' . $e->getMessage());
}