<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';

use Namingo\Cardo\DNS\Providers\Bunny;
use Namingo\Cardo\DNS\Providers\ClouDNS;
use Namingo\Cardo\DNS\Providers\Hetzner;
use Namingo\Cardo\DNS\Providers\RecordValue;

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

expect(
    RecordValue::content('CAA', 'letsencrypt.org', ['flags' => 0, 'tag' => 'issue'])
        === '0 issue letsencrypt.org',
    'CAA split fields must become complete presentation RDATA.'
);
expect(
    RecordValue::content(
        'CAA',
        'letsencrypt.org; validationmethods=dns-01',
        ['flags' => 0, 'tag' => 'issue']
    ) === '0 issue "letsencrypt.org; validationmethods=dns-01"',
    'CAA values containing semicolons and whitespace must be quoted.'
);
expect(
    RecordValue::content(
        'CAA',
        'ca.example; note="quoted"\\path',
        ['flags' => 0, 'tag' => 'iodef']
    ) === '0 iodef "ca.example; note=\\"quoted\\"\\\\path"',
    'CAA quoted values must escape quotes and backslashes.'
);

expect(
    RecordValue::content('SRV', 'sip.example.com.', [
        'priority' => 10,
        'weight' => 20,
        'port' => 5060,
    ]) === '20 5060 sip.example.com.',
    'SRV content for providers with a separate priority field.'
);

expect(
    RecordValue::content('SRV', 'sip.example.com.', [
        'priority' => 10,
        'weight' => 20,
        'port' => 5060,
    ], true) === '10 20 5060 sip.example.com.',
    'SRV full presentation RDATA for textual providers.'
);

$cloudns = (new ReflectionClass(ClouDNS::class))->newInstanceWithoutConstructor();
$apply = new ReflectionMethod(ClouDNS::class, 'applyRecordFields');
$apply->setAccessible(true);

$params = ['record' => 'letsencrypt.org'];
$apply->invokeArgs($cloudns, [&$params, 'CAA', 'letsencrypt.org', [
    'flags' => 128,
    'tag' => 'issuewild',
]]);
expect($params['caa_flag'] === 128, 'ClouDNS CAA flag.');
expect($params['caa_type'] === 'issuewild', 'ClouDNS CAA type.');
expect($params['caa_value'] === 'letsencrypt.org', 'ClouDNS CAA value.');

$params = ['record' => 'sip.example.com.'];
$apply->invokeArgs($cloudns, [&$params, 'SRV', 'sip.example.com.', [
    'priority' => 10,
    'weight' => 20,
    'port' => 5060,
]]);
expect($params['priority'] === 10, 'ClouDNS SRV priority.');
expect($params['weight'] === 20, 'ClouDNS SRV weight.');
expect($params['port'] === 5060, 'ClouDNS SRV port.');

$params = ['record' => '3 1 1 deadbeef'];
$apply->invokeArgs($cloudns, [&$params, 'TLSA', '3 1 1 deadbeef', []]);
expect($params['tlsa_usage'] === 3, 'ClouDNS TLSA usage.');
expect($params['tlsa_selector'] === 1, 'ClouDNS TLSA selector.');
expect($params['tlsa_matching_type'] === 1, 'ClouDNS TLSA matching type.');
expect($params['record'] === 'deadbeef', 'ClouDNS TLSA association data.');

$params = ['record' => '4 2 deadbeef'];
$apply->invokeArgs($cloudns, [&$params, 'SSHFP', '4 2 deadbeef', []]);
expect($params['algorithm'] === 4, 'ClouDNS SSHFP algorithm.');
expect($params['fp_type'] === 2, 'ClouDNS SSHFP fingerprint type.');
expect($params['record'] === 'deadbeef', 'ClouDNS SSHFP fingerprint.');

$params = ['record' => '1 svc.example. alpn=h2 ipv4hint=192.0.2.1'];
$apply->invokeArgs($cloudns, [&$params, 'HTTPS', '1 svc.example. alpn=h2 ipv4hint=192.0.2.1', []]);
expect($params['priority'] === 1, 'ClouDNS HTTPS/SVCB priority.');
expect($params['record'] === 'svc.example.', 'ClouDNS HTTPS/SVCB target.');
expect(
    $params['parameters'] === 'alpn=h2 ipv4hint=192.0.2.1',
    'ClouDNS HTTPS/SVCB parameters.'
);

$bunny = (new ReflectionClass(Bunny::class))->newInstanceWithoutConstructor();
$mapType = new ReflectionMethod(Bunny::class, 'mapTypeToId');
$mapType->setAccessible(true);
expect($mapType->invoke($bunny, 'PTR') === 10, 'Bunny PTR type mapping.');

$hetzner = (new ReflectionClass(Hetzner::class))->newInstanceWithoutConstructor();
$recordValue = new ReflectionMethod(Hetzner::class, 'recordValue');
$recordValue->setAccessible(true);
expect(
    $recordValue->invoke($hetzner, 'CAA', 'letsencrypt.org', ['flags' => 0, 'tag' => 'issue'])
        === '0 issue letsencrypt.org',
    'Hetzner CAA presentation RDATA.'
);

echo "Important record type checks passed.\n";
