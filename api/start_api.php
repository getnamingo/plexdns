<?php

declare(strict_types=1);

use Dotenv\Dotenv;
use Namingo\Cardo\DNS\ResourceNotFoundException;
use Namingo\Cardo\DNS\Service;
use Swoole\Http\Request;
use Swoole\Http\Response;
use Swoole\Http\Server;

$root = dirname(__DIR__);
require_once $root . '/vendor/autoload.php';
require_once __DIR__ . '/helpers.php';

if (!extension_loaded('swoole') || !class_exists(Server::class)) {
    fwrite(STDERR, "The Cardo DNS API requires the Swoole PHP extension.\n");
    exit(1);
}

$dotenv = Dotenv::createImmutable($root);
$dotenv->safeLoad();

$apiToken = trim((string)($_ENV['API_TOKEN'] ?? ''));
$provider = trim((string)($_ENV['API_PROVIDER'] ?? $_ENV['PROVIDER'] ?? ''));
$host = trim((string)($_ENV['API_HOST'] ?? '127.0.0.1'));
$port = filter_var($_ENV['API_PORT'] ?? 9501, FILTER_VALIDATE_INT, [
    'options' => ['min_range' => 1, 'max_range' => 65535],
]);
$logPath = trim((string)($_ENV['API_LOG_PATH'] ?? '/var/log/cardo-dns/api.log'));

if ($apiToken === '' || $provider === '') {
    fwrite(STDERR, "API_TOKEN and API_PROVIDER are required in .env.\n");
    exit(1);
}

if ($port === false) {
    fwrite(STDERR, "API_PORT must be between 1 and 65535.\n");
    exit(1);
}

try {
    $provider = cardoApiProviderDisplayName($provider);
    cardoApiProviderConfig($provider);
    $pdo = cardoApiPdo($root);
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage() . "\n");
    exit(1);
}

$log = cardoApiLogger($logPath);
$service = new Service($pdo);
$server = new Server($host, $port);

$serverOptions = [
    'http_compression' => true,
    'max_request' => 10000,
];

if ($provider === 'Testing') {
    // Testing intentionally uses process-local in-memory SQLite. Keep one
    // non-recycling worker so /install and subsequent requests share state.
    $serverOptions['worker_num'] = 1;
    $serverOptions['max_request'] = 0;
}

$server->set($serverOptions);

$server->on('start', function (Server $server) use ($log, $host, $port, $provider): void {
    $log->info('Cardo DNS API server started.', [
        'listen' => "{$host}:{$port}",
        'provider' => $provider,
    ]);
});

$server->on('request', function (Request $request, Response $response) use ($apiToken, $service, $provider, $log): void {
    $requestId = bin2hex(random_bytes(8));
    $response->header('X-Request-ID', $requestId);
    $response->header('X-Content-Type-Options', 'nosniff');

    $uri = (string)($request->server['request_uri'] ?? '/');
    $path = parse_url($uri, PHP_URL_PATH) ?: '/';
    $method = strtoupper((string)($request->server['request_method'] ?? 'GET'));

    if ($path === '/health' && $method === 'GET') {
        cardoApiRespond($response, 200, ['status' => 'ok']);
        return;
    }

    $headers = array_change_key_case($request->header ?? [], CASE_LOWER);
    $clientToken = '';

    if (isset($headers['authorization']) && preg_match('/^Bearer\s+(\S+)$/i', trim((string)$headers['authorization']), $matches)) {
        $clientToken = $matches[1];
    } elseif (isset($headers['x-api-token'])) {
        $clientToken = trim((string)$headers['x-api-token']);
    }

    if ($clientToken === '' || !hash_equals($apiToken, $clientToken)) {
        cardoApiRespond($response, 401, ['error' => 'Unauthorized']);
        return;
    }

    try {
        $input = cardoApiReadJson($request);

        $domainName = static function (array $payload, Request $request): string {
            $query = $request->get ?? [];
            $value = $payload['domain_name'] ?? $payload['config']['domain_name'] ?? $query['domain_name'] ?? '';
            $value = strtolower(rtrim(trim((string)$value), '.'));
            if ($value === '') {
                throw new InvalidArgumentException('Missing field: domain_name');
            }
            return $value;
        };

        $providerConfig = static function (string $domain) use ($provider): array {
            return cardoApiProviderConfig($provider, $domain);
        };

        if ($path === '/install' && $method === 'POST') {
            $service->install();
            cardoApiRespond($response, 200, ['status' => 'success', 'message' => 'Database structure installed']);
            return;
        }

        if ($path === '/uninstall' && $method === 'POST') {
            $service->uninstall();
            cardoApiRespond($response, 200, ['status' => 'success', 'message' => 'Database structure uninstalled']);
            return;
        }

        if ($path === '/domain' && $method === 'POST') {
            cardoApiRequire($input, ['client_id']);
            $domain = $domainName($input, $request);
            $clientId = cardoApiInteger($input['client_id'], 'client_id', 1);
            $config = $providerConfig($domain);

            $result = $service->createDomain([
                'client_id' => $clientId,
                'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);

            cardoApiRespond($response, 201, ['status' => 'success', 'domain' => $result]);
            return;
        }

        if ($path === '/domain' && $method === 'DELETE') {
            $domain = $domainName($input, $request);
            $service->deleteDomain([
                'config' => json_encode($providerConfig($domain), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ]);

            cardoApiRespond($response, 200, ['status' => 'success', 'message' => 'Domain deleted']);
            return;
        }

        if ($path === '/record' && $method === 'POST') {
            cardoApiRequire($input, ['record_name', 'record_type', 'record_value', 'record_ttl']);
            $domain = $domainName($input, $request);
            $input['domain_name'] = $domain;
            $input['record_type'] = strtoupper(trim((string)$input['record_type']));
            $input['record_ttl'] = cardoApiInteger($input['record_ttl'], 'record_ttl');

            if ($input['record_type'] === 'MX') {
                cardoApiRequire($input, ['record_priority']);
                $input['record_priority'] = cardoApiInteger($input['record_priority'], 'record_priority');
            } elseif ($input['record_type'] === 'SRV') {
                cardoApiRequire($input, ['record_priority', 'record_weight', 'record_port']);
                $input['record_priority'] = cardoApiInteger($input['record_priority'], 'record_priority');
                $input['record_weight'] = cardoApiInteger($input['record_weight'], 'record_weight');
                $input['record_port'] = cardoApiInteger($input['record_port'], 'record_port');
            } elseif ($input['record_type'] === 'CAA' && array_key_exists('record_flags', $input)) {
                $input['record_flags'] = cardoApiInteger($input['record_flags'], 'record_flags');
            }

            $recordId = $service->addRecord(array_merge($input, $providerConfig($domain)));
            cardoApiRespond($response, 201, ['status' => 'success', 'record_id' => $recordId]);
            return;
        }

        if ($path === '/record' && $method === 'PUT') {
            cardoApiRequire($input, ['record_id', 'record_name', 'record_type', 'record_value', 'record_ttl']);
            $domain = $domainName($input, $request);
            $input['domain_name'] = $domain;
            $input['record_id'] = cardoApiInteger($input['record_id'], 'record_id', 1);
            $input['record_type'] = strtoupper(trim((string)$input['record_type']));
            $input['record_ttl'] = cardoApiInteger($input['record_ttl'], 'record_ttl');

            if ($input['record_type'] === 'MX') {
                cardoApiRequire($input, ['record_priority']);
                $input['record_priority'] = cardoApiInteger($input['record_priority'], 'record_priority');
            } elseif ($input['record_type'] === 'SRV') {
                cardoApiRequire($input, ['record_priority', 'record_weight', 'record_port']);
                $input['record_priority'] = cardoApiInteger($input['record_priority'], 'record_priority');
                $input['record_weight'] = cardoApiInteger($input['record_weight'], 'record_weight');
                $input['record_port'] = cardoApiInteger($input['record_port'], 'record_port');
            } elseif ($input['record_type'] === 'CAA' && array_key_exists('record_flags', $input)) {
                $input['record_flags'] = cardoApiInteger($input['record_flags'], 'record_flags');
            }

            $service->updateRecord(array_merge($input, $providerConfig($domain)));
            cardoApiRespond($response, 200, ['status' => 'success', 'message' => 'DNS record updated']);
            return;
        }

        if ($path === '/record' && $method === 'DELETE') {
            cardoApiRequire($input, ['record_id']);
            $domain = $domainName($input, $request);
            $input['domain_name'] = $domain;
            $input['record_id'] = cardoApiInteger($input['record_id'], 'record_id', 1);

            $service->delRecord(array_merge($input, $providerConfig($domain)));
            cardoApiRespond($response, 200, ['status' => 'success', 'message' => 'DNS record deleted']);
            return;
        }

        if ($path === '/sync' && $method === 'POST') {
            $domain = $domainName($input, $request);
            $count = $service->sync($providerConfig($domain));
            cardoApiRespond($response, 200, ['status' => 'success', 'records' => $count]);
            return;
        }

        if ($path === '/dnssec/capabilities' && $method === 'GET') {
            cardoApiRespond($response, 200, [
                'status' => 'success',
                'capabilities' => $service->getDNSSECCapabilities(cardoApiProviderConfig($provider)),
            ]);
            return;
        }

        if ($path === '/dnssec/status' && $method === 'GET') {
            $domain = $domainName($input, $request);
            cardoApiRespond($response, 200, [
                'status' => 'success',
                'dnssec' => $service->getDNSSECStatus($providerConfig($domain)),
            ]);
            return;
        }

        if ($path === '/dnssec/ds' && $method === 'GET') {
            $domain = $domainName($input, $request);
            cardoApiRespond($response, 200, [
                'status' => 'success',
                'ds' => $service->getDSRecords($providerConfig($domain)),
            ]);
            return;
        }

        if ($path === '/dnssec/enable' && $method === 'POST') {
            $domain = $domainName($input, $request);
            cardoApiRespond($response, 200, [
                'status' => 'success',
                'ds' => $service->enableDNSSEC($providerConfig($domain)),
            ]);
            return;
        }

        if ($path === '/dnssec/disable' && $method === 'POST') {
            $domain = $domainName($input, $request);
            $disabled = $service->disableDNSSEC($providerConfig($domain));
            cardoApiRespond($response, 200, ['status' => 'success', 'disabled' => $disabled]);
            return;
        }

        cardoApiRespond($response, 404, ['error' => 'Endpoint not found']);
    } catch (InvalidArgumentException $e) {
        cardoApiRespond($response, 400, ['error' => $e->getMessage()]);
    } catch (ResourceNotFoundException $e) {
        cardoApiRespond($response, 404, ['error' => $e->getMessage()]);
    } catch (RuntimeException $e) {
        $log->error('API operation failed.', [
            'request_id' => $requestId,
            'path' => $path,
            'error' => $e->getMessage(),
        ]);
        cardoApiRespond($response, 502, ['error' => 'Upstream operation failed', 'request_id' => $requestId]);
    } catch (Throwable $e) {
        $log->error('Unhandled API error.', [
            'request_id' => $requestId,
            'path' => $path,
            'error' => $e->getMessage(),
        ]);
        cardoApiRespond($response, 500, ['error' => 'Internal server error', 'request_id' => $requestId]);
    }
});

$server->start();
