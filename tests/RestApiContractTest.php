<?php

/**
 * Lightweight RESTful v2 contract tests.
 *
 * Run with:
 *   php tests/RestApiContractTest.php
 *
 * These tests do not contact Dynadot. They exercise route construction,
 * response status handling, and sparse repeated-field payloads.
 */

if (!defined('DS')) {
    define('DS', DIRECTORY_SEPARATOR);
}

if (!class_exists('Loader')) {
    class Loader
    {
        public static function load($path)
        {
            require_once $path;
        }
    }
}

require_once __DIR__ . '/../apis/dynadot_response.php';
require_once __DIR__ . '/../apis/dynadot_api.php';

function assertSameValue($expected, $actual, $message)
{
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}

function assertTrueValue($condition, $message)
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function invokePrivate($object, $method, array $arguments = [])
{
    $reflection = new ReflectionMethod($object, $method);
    $reflection->setAccessible(true);
    return $reflection->invokeArgs($object, $arguments);
}

function responseStatus($body, $httpCode)
{
    return (new DynadotResponse($body, 'test', [], $httpCode))->status();
}

$api = new DynadotApi('test-key', true, 'test-secret');
$missingSecret = (new DynadotApi('test-key', true, ''))->submit('get_account_balance');
assertSameValue('error', $missingSecret->status(), 'Missing API secret must fail before transport');

$routeCases = [
    ['get_account_balance', [], 'GET', '/accounts/info'],
    ['search', ['domain0' => 'example.com'], 'GET', '/domains/example.com/search'],
    ['tld_price', [], 'GET', '/domains/get_tld_price'],
    ['register', ['domain' => 'example.com'], 'POST', '/domains/example.com/register'],
    ['transfer', ['domain' => 'example.com'], 'POST', '/domains/example.com/transfer_in'],
    ['renew', ['domain' => 'example.com'], 'POST', '/domains/example.com/renew'],
    ['restore', ['domain' => 'example.com'], 'POST', '/domains/example.com/restore'],
    ['domain_info', ['domain' => 'example.com'], 'GET', '/domains/example.com'],
    ['list_domain', [], 'GET', '/domains'],
    ['set_privacy', ['domain' => 'example.com'], 'PUT', '/domains/example.com/privacy'],
    ['set_renew_option', ['domain' => 'example.com'], 'PUT', '/domains/example.com/renew_option'],
    ['lock_domain', ['domain' => 'example.com'], 'PUT', '/domains/example.com/lock_status'],
    ['get_transfer_auth_code', ['domain' => 'example.com'], 'GET', '/domains/example.com/transfer_auth_code'],
    ['get_ns', ['domain' => 'example.com'], 'GET', '/domains/example.com/nameservers'],
    ['set_ns', ['domain' => 'example.com', 'ns0' => 'ns1.example.net'], 'PUT', '/domains/example.com/nameservers'],
    ['server_list', [], 'GET', '/nameservers'],
    ['add_ns', ['host' => 'ns1.example.net'], 'POST', '/nameservers/add_external'],
    ['register_ns', ['host' => 'ns1.example.com', 'ip' => '192.0.2.1'], 'POST', '/nameservers/register'],
    ['delete_ns', ['server_id' => '1'], 'DELETE', '/nameservers/1'],
    ['get_contact', ['contact_id' => '1'], 'GET', '/contacts/1'],
    ['create_contact', ['name' => 'Test'], 'POST', '/contacts'],
    ['edit_contact', ['contact_id' => '1', 'name' => 'Test'], 'PUT', '/contacts/1'],
    ['set_whois', ['domain' => 'example.com', 'registrant_contact' => '1'], 'PUT', '/domains/example.com/contacts'],
    ['get_dnssec', ['domain' => 'example.com'], 'GET', '/domains/example.com/dnssec'],
    ['set_dnssec', ['domain_name' => 'example.com', 'key_tag' => 1, 'algorithm' => 13, 'digest_type' => 2, 'digest' => 'AA'], 'PUT', '/domains/example.com/dnssec'],
    ['clear_dnssec', ['domain_name' => 'example.com'], 'DELETE', '/domains/example.com/dnssec'],
    ['get_dns', ['domain' => 'example.com'], 'GET', '/domains/example.com/dns'],
    ['set_dns2', ['domain' => 'example.com', 'main_record_type0' => 'a'], 'POST', '/domains/example.com/dns'],
    ['set_email_forward', ['domain' => 'example.com', 'username0' => 'a'], 'PUT', '/domains/example.com/email_forwarding']
];

foreach ($routeCases as [$command, $args, $method, $path]) {
    $route = invokePrivate($api, 'buildRestRoute', [$command, $args]);
    assertSameValue($method, $route['method'], $command . ' method mismatch');
    assertSameValue($path, $route['path'], $command . ' path mismatch');
}

$registerRoute = invokePrivate($api, 'buildRestRoute', [
    'register',
    ['domain' => 'example.com', 'registrant_contact' => '42']
]);
assertSameValue(42, $registerRoute['body']['domain']['registrant_contact_id'], 'Registration must include the registrant contact ID');
assertSameValue(
    ['ns1.dynadot.com', 'ns2.dynadot.com'],
    $registerRoute['body']['domain']['nameserver_list'],
    'Registration must include the required nameserver_list'
);
$customRegisterRoute = invokePrivate($api, 'buildRestRoute', [
    'register',
    ['domain' => 'example.com', 'ns0' => 'ns1.example.net', 'ns1' => 'ns2.example.net']
]);
assertSameValue(
    ['ns1.example.net', 'ns2.example.net'],
    $customRegisterRoute['body']['domain']['nameserver_list'],
    'Registration must preserve supplied nameservers'
);
$setNameserverRoute = invokePrivate($api, 'buildRestRoute', [
    'set_ns',
    ['domain' => 'example.com', 'ns0' => 'ns1.example.net', 'ns1' => 'ns2.example.net']
]);
assertSameValue(
    ['ns1.example.net', 'ns2.example.net'],
    $setNameserverRoute['body']['nameserver_list'],
    'Nameserver updates must send nameserver_list'
);

$contactRoute = invokePrivate($api, 'buildRestRoute', [
    'create_contact',
    ['name' => 'Test User', 'email' => 'test@example.net', 'phone_cc' => '1', 'phone_number' => '2025550100']
]);
assertSameValue('1', $contactRoute['body']['contact']['phone_cc'], 'Contact creation must send phone_cc');
assertSameValue('2025550100', $contactRoute['body']['contact']['phone_number'], 'Contact creation must send phone_number');

$postalContactRoute = invokePrivate($api, 'buildRestRoute', [
    'create_contact',
    ['name' => 'Test User', 'email' => 'test@example.net', 'phone_cc' => '880', 'phone_number' => '1712345678', 'zip_code' => '1216', 'country' => 'BD']
]);
assertSameValue('1216', $postalContactRoute['body']['contact']['zip'], 'Contact creation must send the postal code as zip');
assertSameValue('BD', $postalContactRoute['body']['contact']['country'], 'Contact creation must send an ISO country code');

assertSameValue('success', responseStatus('{"code":200,"data":{}}', 200), '2xx JSON response must succeed');
assertSameValue('success', responseStatus('', 204), '204 response without a body must succeed');
assertSameValue('error', responseStatus('{"code":0,"data":{}}', 401), 'HTTP authentication failure must fail');
assertSameValue('error', responseStatus('', 200), 'empty 200 response must not be treated as success');
assertSameValue('error', responseStatus(
    '{"code":404,"message":"Not Found","error":{"description":"Can not find the domain in the account."}}',
    404
), 'Missing pending domains must remain API errors');
assertSameValue('error', responseStatus(
    '{"code":409,"message":"Conflict","error":{"description":"Domain initialization is still in progress. Please try again later."}}',
    409
), 'Nameserver initialization conflicts must remain API errors internally');

$searchResponse = new DynadotResponse(
    '{"code":200,"data":{"domain_name":"example.com","available":true,"price":"10.00"}}',
    'search',
    ['domain0' => 'example.com'],
    200
);
assertSameValue('yes', $searchResponse->response()->SearchResponse->SearchResults[0]->Available, 'Search response must normalize availability');

$trueSearchResponse = new DynadotResponse(
    '{"code":200,"data":{"domain_name":"tuhinwebtest.com","available":"true"}}',
    'search',
    ['domain0' => 'tuhinwebtest.com'],
    200
);
assertSameValue('yes', $trueSearchResponse->response()->SearchResponse->SearchResults[0]->Available, 'String true search availability must be available');

$nameserverResponse = new DynadotResponse(
    '{"code":200,"data":{"nameservers":[{"nameserver":"ns1.dynadot.com"},{"nameserver":"ns2.dynadot.com"}]}}',
    'get_ns',
    ['domain' => 'example.com'],
    200
);
assertSameValue('ns1.dynadot.com', $nameserverResponse->response()->GetNsResponse->NsContent->Host0, 'Nameserver response must expose the first host');

$domainResponse = new DynadotResponse(
    '{"code":200,"data":{"domain":{"domain_name":"example.com","expiration_date":"2030-01-01","locked":true}}}',
    'domain_info',
    ['domain' => 'example.com'],
    200
);
assertSameValue('example.com', $domainResponse->response()->DomainInfoResponse->DomainInfo->Name, 'Domain response must normalize the domain name');
assertSameValue('yes', $domainResponse->response()->DomainInfoResponse->DomainInfo->Locked, 'Domain response must normalize lock state');

$listResponse = new DynadotResponse(
    '{"code":200,"data":{"items":[{"domain_name":"example.com","expiration_date":"2030-01-01"}]}}',
    'list_domain',
    [],
    200
);
assertSameValue('example.com', $listResponse->response()->ListDomainInfoResponse->MainDomains[0]->Name, 'Domain list items must normalize the REST list shape');

$contactResponse = new DynadotResponse(
    '{"code":200,"data":{"contact":{"contact_id":7,"name":"Test User","email":"test@example.net"}}}',
    'get_contact',
    ['contact_id' => 7],
    200
);
assertSameValue('7', $contactResponse->response()->GetContactResponse->GetContact->ContactId, 'Contact response must normalize the contact ID');

$dnsResponse = new DynadotResponse(
    '{"code":200,"data":{"dns":{"main_records":[{"record_type":"a","value":"192.0.2.1"}]}}}',
    'get_dns',
    ['domain' => 'example.com'],
    200
);
assertSameValue('a', $dnsResponse->response()->dns->main_records[0]->record_type, 'DNS response must expose the REST data object');

$dnsPayload = invokePrivate($api, 'buildRestDnsPayload', [[
    'main_record_type0' => 'a',
    'main_record0' => '192.0.2.1',
    'main_record_type2' => 'txt',
    'main_record2' => 'hello',
    'sub_record_type1' => 'cname',
    'subdomain1' => 'www',
    'sub_record1' => 'example.net'
]]);
assertSameValue(2, count($dnsPayload['dns']['main_records']), 'Sparse main DNS records must be preserved');
assertSameValue(1, count($dnsPayload['dns']['sub_records']), 'Sparse sub DNS records must be preserved');

$forwardPayload = invokePrivate($api, 'buildRestEmailForwardPayload', [[
    'username0' => 'one',
    'exist_email0' => 'one@example.net',
    'username2' => 'two',
    'exist_email2' => 'two@example.net'
]]);
assertSameValue(2, count($forwardPayload['email_forwarding_list']), 'Sparse forwarding rows must be preserved');

echo "RESTful v2 contract tests passed.\n";
