<?php

use Dotenv\Dotenv;
use PlexDNS\Service;

// Optional table name overrides
// define('PLEX_TABLE_ZONES', 'plexdns_zones');
// define('PLEX_TABLE_RECORDS', 'plexdns_records');

require_once __DIR__ . '/vendor/autoload.php';

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
        'BIND'        => 'Bind',
        'BIND9'       => 'Bind',
        'BUNNY'       => 'Bunny',
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

// Demo creates/deletes a zone and uninstalls its tables: use a test zone/database.
// Set PROVIDER in .env, or change the default below. Names match DNS_<PROVIDER>_*.
// Examples: Hetzner, Cloudflare, DNSimple, Vultr, Bunny, ClouDNS, Desec, Bind, PowerDNS.
$provider = $_ENV['PROVIDER'] ?? 'Desec';
$domainName = 'example.com'; // Replace with your test domain.
$runDnssec = true;          // Skipped for providers without implemented DNSSEC.
$disableDnssec = false;     // Optional example; remove any parent DS before disabling.

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

    // Cloudflare: API_KEY can be a token (leave EMAIL empty) or email:global_key.
    // For a separate global key, set both EMAIL and API_KEY.
    if ($providerDisplay === 'Cloudflare' && !empty($credentials['EMAIL']) && $apiKey && !str_contains($apiKey, ':')) {
        $apiKey = $credentials['EMAIL'] . ':' . $apiKey;
    }
    if ($providerDisplay === 'ClouDNS') {
        if (!$cloudnsAuthId || !$cloudnsAuthPassword) {
            throw new Exception('ClouDNS requires AUTH_ID and AUTH_PASSWORD.');
        }
    } elseif (!$apiKey) {
        throw new Exception("Missing API Key for provider: $provider");
    }

    // Reuse the same credentials/options for zone, record and DNSSEC operations.
    // Hetzner: API_KEY must be a read/write Console project token, not a legacy DNS token.
    // Bind: API_KEY is username:password; BIND_IP is the API server address.
    $config = [
        'provider' => $providerDisplay,
        'domain_name' => $domainName,
        'apikey' => $apiKey,
        'bindip' => $bindip,
        'powerdnsip' => $powerdnsip,
        'cloudns_auth_id' => $cloudnsAuthId,
        'cloudns_auth_password' => $cloudnsAuthPassword,
        // Optional PowerDNS/BIND secondary server options go here (see README).
    ];
} catch (Exception $e) {
    die("Error: " . $e->getMessage() . "\n");
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
        'config' => json_encode($config),
    ];
    $domain = $service->createDomain($domainOrder);
    print_r($domain);

    // Step 2b: DNSSEC operations (Cloudflare, DNSimple and Vultr are now supported).
    $dnssecProviders = ['Bunny', 'ClouDNS', 'Desec', 'PowerDNS', 'Cloudflare', 'DNSimple', 'Vultr'];
    if ($runDnssec && in_array($providerDisplay, $dnssecProviders, true)) {
        echo "Enabling DNSSEC...\n";
        $ds = $service->enableDNSSEC($config);
        print_r($ds);

        // Publish DS at your registrar if needed; DNSimple handles its registered domains.
        // Empty DS can mean keys are pending: retrieve them again later.
        echo "Getting DNSSEC status...\n";
        $status = $service->getDNSSECStatus($config);
        print_r($status);
        // Cloudflare status 'pending' means signing enabled, parent DS not yet validated.

        echo "Getting DS records only...\n";
        $dsOnly = $service->getDSRecords($config);
        print_r($dsOnly);

        if ($disableDnssec && $providerDisplay !== 'Desec') {
            // Remove the parent DS and allow caches to expire before stopping signing.
            echo $service->disableDNSSEC($config)
                ? "DNSSEC disable request accepted.\n"
                : "DNSSEC was not disabled.\n";
        }
        // deSEC always signs; disabling DNSSEC there is not supported.
    } elseif ($runDnssec) {
        echo "Skipping DNSSEC: not implemented for $providerDisplay.\n";
    }

    // Step 3: Add a DNS record
    echo "Adding a DNS record...\n";
    $recordData = $config + [
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.0.2.1',
        'record_ttl' => 3600,
        // 'record_priority' => 10, // Required for MX/SRV, not A/AAAA/TXT.
        // 'record_weight' => 20, 'record_port' => 5060, // Required for SRV.
    ];
    // addRecord() returns the LOCAL database ID. Use it for updateRecord()/delRecord().
    // Service saves/uses the provider recordId when available; no provider ID is required.
    // Hetzner uses name/type/value; Service supplies the saved old value automatically.
    // Direct Cloudflare/Vultr/Hetzner createRRset() calls still return bool;
    // their optional third argument receives the provider ID (null for Hetzner).
    $recordId = $service->addRecord($recordData);
    echo "DNS record added successfully.\n";

    // Step 4: Update a DNS record
    echo "Updating a DNS record...\n";
    $updateData = $config + [
        'record_id' => $recordId,
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.0.2.2',
        'record_ttl' => 7200,
    ];
    $service->updateRecord($updateData);
    echo "DNS record updated successfully.\n";

    // Step 5: Delete a DNS record
    echo "Deleting a DNS record...\n";
    $deleteData = $config + [
        'record_id' => $recordId,
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.0.2.2',
    ];
    $service->delRecord($deleteData);
    echo "DNS record deleted successfully.\n";

    // Step 6: Delete a domain
    echo "Deleting a domain...\n";
    $service->deleteDomain(['config' => json_encode($config)]);
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