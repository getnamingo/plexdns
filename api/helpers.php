<?php

declare(strict_types=1);

use Monolog\Formatter\LineFormatter;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use Swoole\Http\Request;
use Swoole\Http\Response;

function cardoApiLogger(string $logFilePath): Logger
{
    $logger = new Logger('CardoDNS_API');

    $formatter = new LineFormatter(
        "[%datetime%] %channel%.%level_name%: %message% %context% %extra%\n",
        'Y-m-d H:i:s.u',
        true,
        true
    );

    $consoleHandler = new StreamHandler('php://stdout', Logger::INFO);
    $consoleHandler->setFormatter($formatter);
    $logger->pushHandler($consoleHandler);

    $directory = dirname($logFilePath);
    if (($directory === '.' || is_dir($directory) || @mkdir($directory, 0775, true)) && is_writable($directory)) {
        $fileHandler = new RotatingFileHandler($logFilePath, 14, Logger::INFO);
        $fileHandler->setFormatter($formatter);
        $logger->pushHandler($fileHandler);
    } else {
        $logger->warning('File logging disabled because the log directory is not writable.', ['path' => $directory]);
    }

    return $logger;
}

function cardoApiRespond(Response $response, int $status, array $payload): void
{
    $response->status($status);
    $response->header('Content-Type', 'application/json; charset=utf-8');
    $response->header('Cache-Control', 'no-store');

    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if ($json === false) {
        $response->status(500);
        $json = '{"error":"Failed to encode API response"}';
    }

    $response->end($json);
}

function cardoApiReadJson(Request $request): array
{
    $raw = trim($request->rawContent());
    if ($raw === '') {
        return [];
    }

    try {
        $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new InvalidArgumentException('Invalid JSON payload: ' . $e->getMessage(), 0, $e);
    }

    if (!is_array($decoded)) {
        throw new InvalidArgumentException('JSON payload must be an object.');
    }

    return $decoded;
}

function cardoApiRequire(array $input, array $fields): void
{
    foreach ($fields as $field) {
        if (!array_key_exists($field, $input) || $input[$field] === null) {
            throw new InvalidArgumentException("Missing field: {$field}");
        }
    }
}

function cardoApiInteger(mixed $value, string $field, int $minimum = 0): int
{
    $validated = filter_var($value, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => $minimum],
    ]);

    if ($validated === false) {
        throw new InvalidArgumentException("Invalid {$field}");
    }

    return $validated;
}

function cardoApiProviderDisplayName(string $provider): string
{
    $providers = [
        'ANYCASTDNS' => 'AnycastDNS',
        'BIND' => 'Bind',
        'BIND9' => 'Bind',
        'BUNNY' => 'Bunny',
        'CLOUDFLARE' => 'Cloudflare',
        'CLOUDNS' => 'ClouDNS',
        'DESEC' => 'Desec',
        'DNSIMPLE' => 'DNSimple',
        'DIGITALOCEAN' => 'DigitalOcean',
        'GANDILIVEDNS' => 'GandiLiveDNS',
        'HETZNER' => 'Hetzner',
        'POWERDNS' => 'PowerDNS',
        'SCALEWAY' => 'Scaleway',
        'TESTING' => 'Testing',
        'VULTR' => 'Vultr',
    ];

    $key = strtoupper(str_replace([' ', '-', '_'], '', trim($provider)));

    return $providers[$key] ?? throw new InvalidArgumentException("Unknown DNS provider: {$provider}");
}

function cardoApiProviderEnvPrefix(string $provider): string
{
    return match ($provider) {
        'AnycastDNS' => 'ANYCASTDNS',
        'Bind' => 'BIND',
        'Bunny' => 'BUNNY',
        'Cloudflare' => 'CLOUDFLARE',
        'ClouDNS' => 'CLOUDNS',
        'Desec' => 'DESEC',
        'DNSimple' => 'DNSIMPLE',
        'DigitalOcean' => 'DIGITALOCEAN',
        'GandiLiveDNS' => 'GANDILIVEDNS',
        'Hetzner' => 'HETZNER',
        'PowerDNS' => 'POWERDNS',
        'Scaleway' => 'SCALEWAY',
        'Testing' => 'TESTING',
        'Vultr' => 'VULTR',
        default => throw new InvalidArgumentException("Unknown DNS provider: {$provider}"),
    };
}

function cardoApiProviderConfig(string $provider, ?string $domainName = null): array
{
    $provider = cardoApiProviderDisplayName($provider);
    $prefix = 'DNS_' . cardoApiProviderEnvPrefix($provider) . '_';
    $credentials = [];

    foreach ($_ENV as $key => $value) {
        if (!is_string($key) || !str_starts_with($key, $prefix) || $value === '') {
            continue;
        }

        $credentials[substr($key, strlen($prefix))] = $value;
    }

    $config = ['provider' => $provider];
    if ($domainName !== null && $domainName !== '') {
        $config['domain_name'] = $domainName;
    }

    $apiKey = isset($credentials['API_KEY']) ? trim((string)$credentials['API_KEY']) : '';
    if ($provider === 'Cloudflare' && !empty($credentials['EMAIL']) && $apiKey !== '' && !str_contains($apiKey, ':')) {
        $apiKey = trim((string)$credentials['EMAIL']) . ':' . $apiKey;
    }

    if ($apiKey !== '') {
        $config['apikey'] = $apiKey;
    }

    $map = [
        'BIND_IP' => 'bindip',
        'POWERDNS_IP' => 'powerdnsip',
        'AUTH_ID' => 'cloudns_auth_id',
        'AUTH_PASSWORD' => 'cloudns_auth_password',
        'PROJECT_ID' => 'project_id',
        'PARENT_DOMAIN' => 'parent_domain',
        'SHARING_ID' => 'sharing_id',
        'AUTH_SCHEME' => 'auth_scheme',
        'PDNS_MASTER_IP' => 'pdns_master_ip',
    ];

    foreach ($map as $source => $target) {
        if (isset($credentials[$source]) && $credentials[$source] !== '') {
            $config[$target] = $credentials[$source];
        }
    }

    if ($provider === 'Bind' && empty($config['bindip'])) {
        $config['bindip'] = '127.0.0.1';
    }
    if ($provider === 'PowerDNS' && empty($config['powerdnsip'])) {
        $config['powerdnsip'] = '127.0.0.1';
    }
    if ($provider === 'GandiLiveDNS' && empty($config['auth_scheme'])) {
        $config['auth_scheme'] = 'Bearer';
    }

    foreach ($credentials as $key => $value) {
        if (preg_match('/^API_KEY_NS(\d+)$/', $key, $matches)) {
            $config['apikey_ns' . $matches[1]] = $value;
        } elseif (preg_match('/^BIND_IP_NS(\d+)$/', $key, $matches)) {
            $config['bindip_ns' . $matches[1]] = $value;
        } elseif (preg_match('/^POWERDNS_IP_NS(\d+)$/', $key, $matches)) {
            $config['powerdnsip_ns' . $matches[1]] = $value;
        }
    }

    if ($provider === 'Testing') {
        return $config;
    }

    if ($provider === 'ClouDNS') {
        if (empty($config['cloudns_auth_id']) || empty($config['cloudns_auth_password'])) {
            throw new RuntimeException('ClouDNS requires DNS_CLOUDNS_AUTH_ID and DNS_CLOUDNS_AUTH_PASSWORD.');
        }
        return $config;
    }

    if ($provider === 'Scaleway') {
        if (empty($config['apikey']) || empty($config['project_id'])) {
            throw new RuntimeException('Scaleway requires DNS_SCALEWAY_API_KEY and DNS_SCALEWAY_PROJECT_ID.');
        }
        return $config;
    }

    if (empty($config['apikey'])) {
        throw new RuntimeException("Missing API key for provider: {$provider}");
    }

    return $config;
}

function cardoApiPdo(string $root): PDO
{
    $dbType = strtolower(trim((string)($_ENV['DB_TYPE'] ?? 'mysql')));
    $host = (string)($_ENV['DB_HOST'] ?? '127.0.0.1');
    $dbName = (string)($_ENV['DB_NAME'] ?? '');
    $username = (string)($_ENV['DB_USER'] ?? '');
    $password = (string)($_ENV['DB_PASS'] ?? '');

    if ($dbType === 'sqlite') {
        $configuredPath = trim((string)($_ENV['SQLITE_PATH'] ?? 'database.sqlite'));
        $sqlitePath = str_starts_with($configuredPath, '/') ? $configuredPath : $root . '/' . $configuredPath;
        $dsn = 'sqlite:' . $sqlitePath;
        $username = '';
        $password = '';
    } elseif ($dbType === 'mysql') {
        if ($dbName === '' || $username === '') {
            throw new RuntimeException('MySQL requires DB_NAME and DB_USER.');
        }
        $dsn = "mysql:host={$host};dbname={$dbName};charset=utf8mb4";
    } elseif ($dbType === 'pgsql') {
        if ($dbName === '' || $username === '') {
            throw new RuntimeException('PostgreSQL requires DB_NAME and DB_USER.');
        }
        $dsn = "pgsql:host={$host};dbname={$dbName}";
    } else {
        throw new RuntimeException("Unsupported database type: {$dbType}");
    }

    $pdo = new PDO($dsn, $username, $password, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_PERSISTENT => false,
    ]);

    if ($dbType === 'sqlite') {
        $pdo->exec('PRAGMA foreign_keys = ON;');
    }

    return $pdo;
}
