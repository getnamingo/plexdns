<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Namingo\Cardo\DNS\ResourceNotFoundException;
use Namingo\Cardo\DNS\Service;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$service = new Service($db);
$service->install();

try {
    $service->delRecord([
        'provider' => 'Testing',
        'domain_name' => 'missing.test',
        'record_id' => 1,
    ]);
    throw new RuntimeException('Expected missing domain to fail.');
} catch (ResourceNotFoundException $exception) {
    expect($exception->getMessage() === 'Domain does not exist.', 'Expected local domain not-found error.');
}

$service->createDomain([
    'client_id' => 1,
    'config' => json_encode(['provider' => 'Testing', 'domain_name' => 'example.test'], JSON_THROW_ON_ERROR),
]);

try {
    $service->updateRecord([
        'provider' => 'Testing',
        'domain_name' => 'example.test',
        'record_id' => 999,
        'record_name' => 'www',
        'record_type' => 'A',
        'record_value' => '192.0.2.2',
        'record_ttl' => 300,
    ]);
    throw new RuntimeException('Expected missing record to fail.');
} catch (ResourceNotFoundException $exception) {
    expect($exception->getMessage() === 'Record does not exist.', 'Expected local record not-found error.');
}

try {
    $service->delRecord([
        'provider' => 'Testing',
        'domain_name' => 'example.test',
        'record_id' => 999,
    ]);
    throw new RuntimeException('Expected missing record deletion to fail.');
} catch (ResourceNotFoundException $exception) {
    expect($exception->getMessage() === 'Record does not exist.', 'Expected delete not-found error.');
}

echo "Service state error checks passed.\n";
