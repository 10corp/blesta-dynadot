<?php

/**
 * Dynadot RESTful v2 API Transport Layer
 *
 * Provides the HTTP/JSON transport, endpoint routing, and HMAC-SHA256 request
 * signing between the Blesta registrar module (dynadot.php) and Dynadot's
 * RESTful API v2.0.0.
 *
 * API Architecture & Protocol Notes (For Junior Developers):
 * - Base URLs:
 *   - Live:    https://api.dynadot.com/restful/v2
 *   - Sandbox: https://api-sandbox.dynadot.com/restful/v2
 * - Authentication Headers:
 *   1. Authorization: Bearer {API_KEY}
 *   2. X-Request-ID: {UUID v4} (uniquely identifies each request)
 *   3. X-Signature: {Base64 HMAC-SHA256} (signs the request using the API Secret)
 * - Signature Formula (per https://www.dynadot.com/domain/api-document):
 *   stringToSign = apiKey + "\n" + fullPathAndQuery + "\n" + xRequestId + "\n" + requestBody
 *   signature    = base64_encode(hash_hmac('sha256', stringToSign, apiSecret, true))
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis
 * @author 10Corp <cs@10corp.com>
 * @copyright Copyright (c) 2026, 10Corp
 * @link https://www.10corp.com
 */
class DynadotApi
{
    // =========================================================================
    // Constants & Properties
    // =========================================================================

    private const LIVE_BASE_URL = 'https://api.dynadot.com';
    private const SANDBOX_BASE_URL = 'https://api-sandbox.dynadot.com';
    private const API_PREFIX = '/restful/v2';

    /**
     * @var string Dynadot API Key (used as Bearer token and in signature string).
     */
    private $key;

    /**
     * @var string Dynadot API Secret (used as the HMAC-SHA256 key for X-Signature).
     */
    private $secret;

    /**
     * @var bool Sandbox mode toggle (true = api-sandbox.dynadot.com, false = api.dynadot.com).
     */
    private $sandbox;

    /**
     * @var array Stores 'url' and 'args' of the most recent API call for Blesta's module log.
     */
    private $last_request = [];

    /**
     * @var int HTTP status code returned by the most recent cURL execution.
     */
    public $httpcode = 0;

    /**
     * @var bool Local file debug logging flag. Keep false in production.
     */
    private $enable_log = false;

    /**
     * @var string Absolute path to the local debug log file.
     */
    private $log_file;

    // =========================================================================
    // Constructor
    // =========================================================================

    /**
     * Initializes the Dynadot RESTful v2 API client.
     *
     * @param string $key     Dynadot API Key (Tools > API)
     * @param bool   $sandbox True for Sandbox environment, false for Production
     * @param string $secret  Dynadot API Secret for HMAC-SHA256 request signing
     */
    public function __construct($key, $sandbox = true, $secret = '')
    {
        $this->key = trim((string) $key);
        $this->sandbox = (bool) $sandbox;
        $this->secret = trim((string) $secret);
        $this->log_file = dirname(__FILE__) . DS . 'dynadot_api.log';
    }

    // =========================================================================
    // Public Command Dispatcher
    // =========================================================================

    /**
     * Translates a module command and argument array into a signed RESTful v2 HTTP request.
     *
     * Junior Dev Note:
     * dynadot.php calls $api->submit('register', $args) using command names.
     * buildRestRoute() maps each command name to its RESTful v2 HTTP method,
     * URI path, query parameters, and JSON body payload.
     *
     * @param string $command Internal command name (e.g., 'search', 'register', 'domain_info')
     * @param array  $args    Command arguments passed from dynadot.php
     * @return DynadotResponse Normalized response object
     */
    public function submit($command, array $args = [])
    {
        Loader::load(dirname(__FILE__) . DS . 'dynadot_response.php');

        if ($this->key === '' || $this->secret === '') {
            $missing = $this->key === '' ? 'API key' : 'API secret';
            return new DynadotResponse(json_encode([
                'code' => 401,
                'message' => 'Invalid API credentials',
                'error' => [
                    'description' => 'Dynadot RESTful v2 requires a non-empty ' . $missing . '.'
                ]
            ]), $command, $args, 0);
        }

        $route = $this->buildRestRoute($command, $args);
        $method = $route['method'];
        $path = self::API_PREFIX . $route['path'];
        $query = !empty($route['query']) ? '?' . http_build_query($route['query']) : '';
        $full_path_and_query = $path . $query;

        $base_url = $this->sandbox ? self::SANDBOX_BASE_URL : self::LIVE_BASE_URL;
        $url = $base_url . $full_path_and_query;

        $body_json = '';
        if ($route['body'] !== null && in_array($method, ['POST', 'PUT', 'PATCH'], true)) {
            $body_json = json_encode($route['body'], JSON_UNESCAPED_SLASHES);
            if ($body_json === false) {
                $body_json = '{}';
            }
        }

        // Generate UUID v4 for X-Request-ID and compute HMAC-SHA256 X-Signature
        $request_id = $this->generateUuidV4();
        $signature = $this->generateSignature($full_path_and_query, $request_id, $body_json);

        // Save sanitized metadata for Blesta's Tools -> Logs -> Module audit log
        $this->last_request = [
            'url' => $method . ' ' . $url,
            'args' => [
                'command' => $command,
                'key' => $this->key,
                'request_id' => $request_id,
                'query' => $route['query'],
                'body' => $route['body']
            ]
        ];

        $headers = [
            'Accept: application/json',
            'Authorization: Bearer ' . $this->key,
            'X-Request-ID: ' . $request_id
        ];

        if ($signature !== '') {
            $headers[] = 'X-Signature: ' . $signature;
        }

        if ($body_json !== '') {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        if ($body_json !== '') {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body_json);
        }

        $response = curl_exec($ch);
        $this->httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_error = curl_error($ch);
        unset($ch);

        if ($this->enable_log) {
            $this->writeApiLog($command, $this->last_request['args'], (string) $response, $curl_error);
        }

        if ($response === false) {
            $this->httpcode = 0;
            $response = json_encode([
                'code' => 503,
                'message' => 'Service Unavailable',
                'error' => [
                    'description' => 'Unable to connect to the Dynadot RESTful v2 API: ' . $curl_error
                ]
            ]);
        }

        return new DynadotResponse((string) $response, $command, $args, $this->httpcode);
    }

    // =========================================================================
    // RESTful v2 Command Router & Payload Builder
    // =========================================================================

    /**
     * Maps internal module commands to Dynadot RESTful v2 endpoints and JSON payloads.
     *
     * @param string $command Module command name
     * @param array  $args    Module command arguments
     * @return array{method: string, path: string, query: array, body: array|null}
     */
    private function buildRestRoute($command, array $args)
    {
        $domain = rawurlencode(strtolower(trim((string) ($args['domain'] ?? ($args['domain_name'] ?? ($args['domain0'] ?? ''))))));

        switch ($command) {
            // -----------------------------------------------------------------
            // Account & Connection Validation
            // -----------------------------------------------------------------
            case 'get_account_balance':
            case 'account_info':
            case 'get_info':
                return [
                    'method' => 'GET',
                    'path' => '/accounts/info',
                    'query' => [],
                    'body' => null
                ];

            // -----------------------------------------------------------------
            // Domain Search & Pricing
            // -----------------------------------------------------------------
            case 'search':
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain . '/search',
                    'query' => [
                        'show_price' => isset($args['show_price']) ? (string) $args['show_price'] : '1',
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD'))
                    ],
                    'body' => null
                ];

            case 'tld_price':
            case 'domain_get_tld_price':
                return [
                    'method' => 'GET',
                    'path' => '/domains/get_tld_price',
                    'query' => [
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD')),
                        'page' => (int) ($args['page_index'] ?? ($args['page'] ?? 1)),
                        'page_size' => (int) ($args['count_per_page'] ?? ($args['page_size'] ?? 100))
                    ],
                    'body' => null
                ];

            // -----------------------------------------------------------------
            // Domain Lifecycle (Register, Transfer, Renew, Restore)
            // -----------------------------------------------------------------
            case 'register':
                $ns_list = $this->extractNumberedValues($args, 'ns');
                $domain_body = [
                    'duration' => (int) ($args['duration'] ?? ($args['years'] ?? 1)),
                    'privacy' => (string) ($args['privacy'] ?? 'full')
                ];
                if (!empty($args['registrant_contact'])) {
                    $domain_body['registrant_contact_id'] = (int) $args['registrant_contact'];
                }
                if (!empty($args['admin_contact'])) {
                    $domain_body['admin_contact_id'] = (int) $args['admin_contact'];
                }
                if (!empty($args['technical_contact'])) {
                    $domain_body['tech_contact_id'] = (int) $args['technical_contact'];
                }
                if (!empty($args['billing_contact'])) {
                    $domain_body['billing_contact_id'] = (int) $args['billing_contact'];
                }
                if (!empty($ns_list)) {
                    $domain_body['nameserver_list'] = $ns_list;
                } else {
                    $domain_body['nameserver_list'] = [
                        'ns1.dynadot.com',
                        'ns2.dynadot.com'
                    ];
                }
                return [
                    'method' => 'POST',
                    'path' => '/domains/' . $domain . '/register',
                    'query' => [],
                    'body' => [
                        'domain' => $domain_body,
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD'))
                    ]
                ];

            case 'transfer':
            case 'transfer_in':
                $transfer_domain = [
                    'auth_code' => (string) ($args['auth'] ?? ($args['auth_code'] ?? ''))
                ];
                if (!empty($args['registrant_contact'])) {
                    $transfer_domain['registrant_contact_id'] = (int) $args['registrant_contact'];
                }
                if (!empty($args['admin_contact'])) {
                    $transfer_domain['admin_contact_id'] = (int) $args['admin_contact'];
                }
                if (!empty($args['technical_contact'])) {
                    $transfer_domain['tech_contact_id'] = (int) $args['technical_contact'];
                }
                if (!empty($args['billing_contact'])) {
                    $transfer_domain['billing_contact_id'] = (int) $args['billing_contact'];
                }
                if (!empty($args['name_servers'])) {
                    $transfer_domain['nameserver_list'] = is_array($args['name_servers'])
                        ? array_values($args['name_servers'])
                        : array_values(array_filter(array_map('trim', explode(',', (string) $args['name_servers']))));
                } else {
                    $transfer_domain['nameserver_list'] = [
                        'ns1.dynadot.com',
                        'ns2.dynadot.com'
                    ];
                }
                return [
                    'method' => 'POST',
                    'path' => '/domains/' . $domain . '/transfer_in',
                    'query' => [],
                    'body' => [
                        'domain' => $transfer_domain,
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD'))
                    ]
                ];

            case 'renew':
                return [
                    'method' => 'POST',
                    'path' => '/domains/' . $domain . '/renew',
                    'query' => [],
                    'body' => [
                        'duration' => (int) ($args['duration'] ?? ($args['years'] ?? 1)),
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD'))
                    ]
                ];

            case 'restore':
                return [
                    'method' => 'POST',
                    'path' => '/domains/' . $domain . '/restore',
                    'query' => [],
                    'body' => [
                        'currency' => strtoupper((string) ($args['currency'] ?? 'USD'))
                    ]
                ];

            // -----------------------------------------------------------------
            // Domain Info, Listing, Lock, Privacy & Renew Option
            // -----------------------------------------------------------------
            case 'domain_info':
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain,
                    'query' => [],
                    'body' => null
                ];

            case 'list_domain':
            case 'domain_list':
                return [
                    'method' => 'GET',
                    'path' => '/domains',
                    'query' => [
                        'page' => (int) ($args['page_index'] ?? ($args['page'] ?? 1)),
                        'page_size' => (int) ($args['count_per_page'] ?? ($args['page_size'] ?? 100))
                    ],
                    'body' => null
                ];

            case 'set_privacy':
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/privacy',
                    'query' => [],
                    'body' => [
                        'option' => (string) ($args['option'] ?? 'full'),
                        'whois_privacy_option' => (string) ($args['whois_privacy_option'] ?? 'yes')
                    ]
                ];

            case 'set_renew_option':
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/renew_option',
                    'query' => [],
                    'body' => [
                        'renew_option' => (string) ($args['renew_option'] ?? 'auto')
                    ]
                ];

            case 'lock_domain':
            case 'set_domain_lock_status':
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/lock_status',
                    'query' => [],
                    'body' => [
                        'lock_domain' => (string) ($args['lock_domain'] ?? 'lock')
                    ]
                ];

            case 'get_transfer_auth_code':
                $query = [];
                if (isset($args['unlock_domain_for_transfer'])) {
                    $query['unlock_domain_for_transfer'] = (string) $args['unlock_domain_for_transfer'];
                }
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain . '/transfer_auth_code',
                    'query' => $query,
                    'body' => null
                ];

            // -----------------------------------------------------------------
            // Nameservers & Glue Records (Hosts)
            // -----------------------------------------------------------------
            case 'get_ns':
            case 'get_nameserver':
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain . '/nameservers',
                    'query' => [],
                    'body' => null
                ];

            case 'set_ns':
            case 'set_nameserver':
                $ns_list = $this->extractNumberedValues($args, 'ns');
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/nameservers',
                    'query' => [],
                    'body' => [
                        'nameserver_list' => $ns_list
                    ]
                ];

            case 'server_list':
            case 'nameserver_list':
                return [
                    'method' => 'GET',
                    'path' => '/nameservers',
                    'query' => [],
                    'body' => null
                ];

            case 'add_ns':
            case 'nameserver_add_external':
                return [
                    'method' => 'POST',
                    'path' => '/nameservers/add_external',
                    'query' => [],
                    'body' => [
                        'server_name' => (string) ($args['host'] ?? ($args['server_name'] ?? ''))
                    ]
                ];

            case 'register_ns':
            case 'nameserver_register':
                return [
                    'method' => 'POST',
                    'path' => '/nameservers/register',
                    'query' => [],
                    'body' => [
                        'nameserver' => [
                            'server_name' => (string) ($args['host'] ?? ($args['server_name'] ?? '')),
                            'ip' => (string) ($args['ip'] ?? '')
                        ]
                    ]
                ];

            case 'delete_ns':
            case 'nameserver_delete':
                $server_id = rawurlencode((string) ($args['server_id'] ?? ($args['host'] ?? '')));
                return [
                    'method' => 'DELETE',
                    'path' => '/nameservers/' . $server_id,
                    'query' => [],
                    'body' => null
                ];

            // -----------------------------------------------------------------
            // WHOIS Contacts
            // -----------------------------------------------------------------
            case 'get_contact':
                $contact_id = rawurlencode((string) ($args['contact_id'] ?? ''));
                return [
                    'method' => 'GET',
                    'path' => '/contacts/' . $contact_id,
                    'query' => [],
                    'body' => null
                ];

            case 'create_contact':
            case 'contact_create':
                return [
                    'method' => 'POST',
                    'path' => '/contacts',
                    'query' => [],
                    'body' => [
                        'contact' => $this->buildRestContactPayload($args)
                    ]
                ];

            case 'edit_contact':
            case 'contact_update':
                $contact_id = rawurlencode((string) ($args['contact_id'] ?? ''));
                return [
                    'method' => 'PUT',
                    'path' => '/contacts/' . $contact_id,
                    'query' => [],
                    'body' => [
                        'contact' => $this->buildRestContactPayload($args)
                    ]
                ];

            case 'set_whois':
            case 'set_contacts':
                $contacts_body = [];
                if (!empty($args['registrant_contact'])) {
                    $contacts_body['registrant_contact_id'] = (int) $args['registrant_contact'];
                }
                if (!empty($args['admin_contact'])) {
                    $contacts_body['admin_contact_id'] = (int) $args['admin_contact'];
                }
                if (!empty($args['technical_contact'])) {
                    $contacts_body['tech_contact_id'] = (int) $args['technical_contact'];
                }
                if (!empty($args['billing_contact'])) {
                    $contacts_body['billing_contact_id'] = (int) $args['billing_contact'];
                }
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/contacts',
                    'query' => [],
                    'body' => [
                        'contacts' => $contacts_body
                    ]
                ];

            // -----------------------------------------------------------------
            // DNSSEC
            // -----------------------------------------------------------------
            case 'get_dnssec':
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain . '/dnssec',
                    'query' => [],
                    'body' => null
                ];

            case 'set_dnssec':
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/dnssec',
                    'query' => [],
                    'body' => [
                        'key_tag' => (int) ($args['key_tag'] ?? 0),
                        'algorithm' => (int) ($args['algorithm'] ?? 0),
                        'digest_type' => (int) ($args['digest_type'] ?? 0),
                        'digest' => (string) ($args['digest'] ?? '')
                    ]
                ];

            case 'clear_dnssec':
                return [
                    'method' => 'DELETE',
                    'path' => '/domains/' . $domain . '/dnssec',
                    'query' => [],
                    'body' => null
                ];

            // -----------------------------------------------------------------
            // DNS Zone Records & Email Forwarding
            // -----------------------------------------------------------------
            case 'get_dns':
                return [
                    'method' => 'GET',
                    'path' => '/domains/' . $domain . '/dns',
                    'query' => [],
                    'body' => null
                ];

            case 'set_dns2':
            case 'set_dns':
                return [
                    'method' => 'POST',
                    'path' => '/domains/' . $domain . '/dns',
                    'query' => [],
                    'body' => $this->buildRestDnsPayload($args)
                ];

            case 'set_email_forward':
            case 'set_email_forwarding':
                return [
                    'method' => 'PUT',
                    'path' => '/domains/' . $domain . '/email_forwarding',
                    'query' => [],
                    'body' => $this->buildRestEmailForwardPayload($args)
                ];

            default:
                return [
                    'method' => 'GET',
                    'path' => '/' . ltrim($command, '/'),
                    'query' => $args,
                    'body' => null
                ];
        }
    }

    // =========================================================================
    // Signing & Payload Helpers
    // =========================================================================

    /**
     * Generates the Base64-encoded HMAC-SHA256 signature required by Dynadot RESTful v2.
     *
     * Formula:
     * stringToSign = apiKey + "\n" + fullPathAndQuery + "\n" + xRequestId + "\n" + requestBody
     *
     * @param string $full_path_and_query e.g., "/restful/v2/domains/example.com/search?show_price=1"
     * @param string $request_id          UUID v4 sent in X-Request-ID header
     * @param string $request_body        Raw JSON string sent in the request body (or "")
     * @return string Base64-encoded HMAC-SHA256 signature
     */
    private function generateSignature($full_path_and_query, $request_id, $request_body = '')
    {
        if ($this->secret === '') {
            return '';
        }

        $string_to_sign = $this->key . "\n"
            . $full_path_and_query . "\n"
            . $request_id . "\n"
            . $request_body;

        return base64_encode(hash_hmac('sha256', $string_to_sign, $this->secret, true));
    }

    /**
     * Generates a standards-compliant RFC 4122 UUID v4 for the X-Request-ID header.
     *
     * @return string
     */
    private function generateUuidV4()
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Extracts 0-indexed numbered arguments (e.g., ns0, ns1, ns2) into a sequential array.
     *
     * @param array  $args
     * @param string $prefix
     * @return array
     */
    private function extractNumberedValues(array $args, $prefix)
    {
        $values = [];
        for ($i = 0; $i < 20; $i++) {
            if (isset($args[$prefix . $i]) && trim((string) $args[$prefix . $i]) !== '') {
                $values[] = trim((string) $args[$prefix . $i]);
            }
        }
        return $values;
    }

    /**
     * Formats contact arguments from dynadot.php into a RESTful v2 contact object.
     *
     * @param array $args
     * @return array
     */
    private function buildRestContactPayload(array $args)
    {
        $map = [
            'name' => 'name',
            'email' => 'email',
            'phone_cc' => 'phone_cc',
            'phone_number' => 'phone_number',
            'phone' => 'phone_number',
            'phone_num' => 'phone_number',
            'organization' => 'organization',
            'address1' => 'address1',
            'address2' => 'address2',
            'city' => 'city',
            'state' => 'state',
            'zip' => 'zip',
            'zip_code' => 'zip',
            'postal_code' => 'zip',
            'country' => 'country'
        ];

        $contact = [];
        foreach ($map as $src => $dest) {
            if (isset($args[$src]) && $args[$src] !== '') {
                $contact[$dest] = (string) $args[$src];
            }
        }

        return $contact;
    }

    /**
     * Formats numbered DNS arguments (main_record_type0, subdomain0, etc.) into RESTful v2 DNS arrays.
     *
     * @param array $args
     * @return array
     */
    private function buildRestDnsPayload(array $args)
    {
        $main_records = [];
        foreach ($this->getNumberedIndexes($args, 'main_record_type') as $i) {
            $record = [
                'record_type' => strtolower((string) $args['main_record_type' . $i]),
                'value' => (string) ($args['main_record' . $i] ?? '')
            ];
            if (isset($args['main_recordx' . $i]) && $args['main_recordx' . $i] !== '') {
                $record['value2'] = (string) $args['main_recordx' . $i];
            }
            $main_records[] = $record;
        }

        $sub_records = [];
        foreach ($this->getNumberedIndexes($args, 'sub_record_type') as $i) {
            $record = [
                'subdomain' => (string) ($args['subdomain' . $i] ?? ''),
                'record_type' => strtolower((string) $args['sub_record_type' . $i]),
                'value' => (string) ($args['sub_record' . $i] ?? '')
            ];
            if (isset($args['sub_recordx' . $i]) && $args['sub_recordx' . $i] !== '') {
                $record['value2'] = (string) $args['sub_recordx' . $i];
            }
            $sub_records[] = $record;
        }

        return [
            'dns' => [
                'main_records' => $main_records,
                'sub_records' => $sub_records
            ]
        ];
    }

    /**
     * Formats numbered email forwarding rules into a RESTful v2 payload.
     *
     * @param array $args
     * @return array
     */
    private function buildRestEmailForwardPayload(array $args)
    {
        $forwards = [];
        foreach ($this->getNumberedIndexes($args, 'username') as $i) {
            $forwards[] = [
                'username' => (string) $args['username' . $i],
                'exist_email' => (string) ($args['exist_email' . $i] ?? '')
            ];
        }

        return [
            'forward_type' => (string) ($args['forward_type'] ?? 'forward'),
            'email_forwarding_list' => $forwards
        ];
    }

    /**
     * Returns the numeric suffixes present for a repeated request field.
     *
     * Browser row deletion can leave gaps (for example, field 0 and field 2).
     * Payload construction must preserve those remaining rows instead of
     * stopping at the first gap.
     *
     * @param array  $args
     * @param string $prefix
     * @return int[]
     */
    private function getNumberedIndexes(array $args, $prefix)
    {
        $indexes = [];
        $pattern = '/^' . preg_quote($prefix, '/') . '(\d+)$/';
        foreach (array_keys($args) as $key) {
            if (preg_match($pattern, (string) $key, $matches)) {
                $indexes[] = (int) $matches[1];
            }
        }

        sort($indexes, SORT_NUMERIC);
        return array_values(array_unique($indexes));
    }

    // =========================================================================
    // Getters & Diagnostics
    // =========================================================================

    /**
     * Returns metadata ('url' and 'args') from the last API request for Blesta logging.
     *
     * @return array
     */
    public function lastRequest()
    {
        return $this->last_request;
    }

    /**
     * Writes masked request/response logs to local disk when $this->enable_log is true.
     *
     * @param string      $command
     * @param array       $args
     * @param string      $response
     * @param string|null $error
     */
    private function writeApiLog($command, array $args, $response, $error = null)
    {
        if (!empty($args['key'])) {
            $args['key'] = substr((string) $args['key'], 0, 4) . '****';
        }
        if (!empty($args['secret'])) {
            $args['secret'] = '****';
        }

        $log  = "==============================\n";
        $log .= "Time: " . date('Y-m-d H:i:s') . "\n";
        $log .= "Command: {$command}\n";
        $log .= "HTTP Code: {$this->httpcode}\n";
        $log .= "Request: " . json_encode($args) . "\n";

        if (!empty($error)) {
            $log .= "cURL Error: {$error}\n";
        }

        $log .= "Response: {$response}\n";
        $log .= "==============================\n\n";

        @file_put_contents($this->log_file, $log, FILE_APPEND | LOCK_EX);
    }
}