<?php

/**
 * Dynadot RESTful v2 Response Parser & Normalization Adapter
 *
 * Decodes JSON responses returned by Dynadot's RESTful v2 API and normalizes
 * them into the object properties expected by dynadot.php.
 *
 * Reading Guide for Junior Developers:
 * 1. Dynadot RESTful v2 returns a standard top-level JSON structure:
 *    - Success: {"code": 200, "message": "Success", "data": { ... }}
 *    - Error:   {"code": 400, "message": "Bad Request", "error": {"description": "..."}}
 * 2. status() requires a 2xx HTTP response, then evaluates the payload status
 *    code; an empty 204 response is also successful.
 * 3. errors() extracts human-readable error strings from error.description or message.
 * 4. normalizeResponse() maps RESTful v2 snake_case data fields onto the PascalCase
 *    response objects (e.g., DomainInfoResponse, SearchResponse, GetContactResponse)
 *    that dynadot.php reads throughout the module.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis
 * @author 10Corp <cs@10corp.com>
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotResponse
{
    /**
     * @var string Raw HTTP response body for Blesta module logging.
     */
    private $raw;

    /**
     * @var stdClass|null Decoded and normalized response object.
     */
    private $json;

    /**
     * @var string The module command that produced this response.
     */
    private $command;

    /**
     * @var array The original arguments passed to DynadotApi::submit().
     */
    private $args;

    /**
     * @var int HTTP status code from cURL.
     */
    private $http_code;

    /**
     * Initializes and normalizes the Dynadot RESTful v2 response.
     *
     * @param string $response  Raw JSON body returned by Dynadot
     * @param string $command   Module command name (optional)
     * @param array  $args      Module request arguments (optional)
     * @param int    $http_code HTTP status code (optional)
     */
    public function __construct($response, $command = '', array $args = [], $http_code = 200)
    {
        $this->raw = (string) $response;
        $this->command = (string) $command;
        $this->args = $args;
        $this->http_code = (int) $http_code;

        $decoded = json_decode($this->raw);
        $this->json = is_object($decoded) ? $this->normalizeResponse($decoded) : null;
    }

    /**
     * Determines whether the RESTful v2 API request succeeded or failed.
     *
     * @return string 'success' or 'error'
     */
    public function status()
    {
        if ($this->http_code < 200 || $this->http_code >= 300) {
            return 'error';
        }

        // DELETE endpoints may legitimately return HTTP 204 without a body.
        if ($this->http_code === 204 && trim($this->raw) === '') {
            return 'success';
        }

        if (!$this->json) {
            return 'error';
        }

        // HTTP status is authoritative; payload codes provide API-level detail.
        if (isset($this->json->code)) {
            $code = (int) $this->json->code;
            if (isset($this->json->error)) {
                return 'error';
            }
            return ($code === 0 || ($code >= 200 && $code < 300)) ? 'success' : 'error';
        }

        // 2. Fallback: Check command-specific wrappers (for backward compatibility)
        foreach ($this->json as $val) {
            if (is_object($val)) {
                if (isset($val->ResponseCode)) {
                    return in_array((string) $val->ResponseCode, ['0', '200', '201', '202'], true)
                        ? 'success'
                        : 'error';
                }
                if (isset($val->SuccessCode)) {
                    return (string) $val->SuccessCode === '0' ? 'success' : 'error';
                }
            }
        }

        if (!isset($this->json->error)) {
            return 'success';
        }

        return 'error';
    }

    /**
     * Returns the decoded and normalized response object.
     *
     * @return stdClass|null
     */
    public function response()
    {
        return $this->json;
    }

    /**
     * Returns the raw, unparsed JSON response string for Blesta module logs.
     *
     * @return string
     */
    public function raw()
    {
        return $this->raw;
    }

    /**
     * Extracts human-readable error messages on failure, or returns false on success.
     *
     * @return array|false
     */
    public function errors()
    {
        if ($this->status() !== 'error') {
            return false;
        }

        $errors = [];

        if ($this->json) {
            // 1. RESTful v2 standard error object: {"code": 400, "message": "...", "error": {"description": "..."}}
            if (isset($this->json->error)) {
                if (is_object($this->json->error) && !empty($this->json->error->description)) {
                    $errors[] = (string) $this->json->error->description;
                } elseif (is_object($this->json->error) && !empty($this->json->error->message)) {
                    $errors[] = (string) $this->json->error->message;
                } elseif (is_string($this->json->error) && $this->json->error !== '') {
                    $errors[] = $this->json->error;
                }
            }

            // 2. Top-level RESTful v2 error message when error.description is omitted
            if (empty($errors) && !empty($this->json->message) && strtolower((string) $this->json->message) !== 'success') {
                $errors[] = (string) $this->json->message;
            }

            // 3. Fallback for nested Error / Message fields
            foreach ($this->json as $val) {
                if (is_object($val)) {
                    if (!empty($val->Error)) {
                        $errors[] = (string) $val->Error;
                    }
                    if (isset($val->Status) && $val->Status === 'error' && !empty($val->Message)) {
                        $errors[] = (string) $val->Message;
                    }
                }
            }
        }

        $errors = array_values(array_unique(array_filter($errors)));
        return !empty($errors) ? $errors : ['Unknown Error'];
    }

    // =========================================================================
    // RESTful v2 -> Module Property Normalization
    // =========================================================================

    /**
     * Adapts RESTful v2 `data` structures into the PascalCase response properties
     * accessed by dynadot.php.
     *
     * @param stdClass $payload
     * @return stdClass
     */
    private function normalizeResponse(stdClass $payload)
    {
        $data = (isset($payload->data) && is_object($payload->data)) ? $payload->data : $payload;

        // Expose top-level v2 dns and email_forwarding objects if present inside data->glue_info or data->dns
        $glue_info = $data->glue_info ?? ($payload->glue_info ?? null);
        if (is_object($glue_info)) {
            $payload->dns = (object) [
                'main_records' => $glue_info->dns_main_list ?? [],
                'sub_records' => $glue_info->dns_sub_list ?? []
            ];
            $payload->email_forwarding = (object) [
                'email_forward_type' => $glue_info->email_forward_type ?? '',
                'email_alias_list' => $glue_info->email_alias_list ?? []
            ];
            $payload->GetEmailForwardingResponse = (object) [
                'EmailForwardingList' => $glue_info->email_alias_list ?? []
            ];
        } elseif (isset($data->dns) && !isset($payload->dns)) {
            $payload->dns = $data->dns;
        }

        // 1. Search Response (checkAvailability & checkTransferAvailability)
        if ($this->command === 'search' || isset($data->available) || isset($data->domain_name)) {
            if (!isset($payload->SearchResponse)) {
                $domain_name = $data->domain_name ?? ($this->args['domain0'] ?? ($this->args['domain'] ?? ''));
                $available = $data->available ?? false;
                $is_avail = $available === true
                    || in_array(strtolower(trim((string) $available)), ['yes', 'true', '1', 'available', 'free'], true);

                $payload->SearchResponse = (object) [
                    'SearchResults' => [
                        (object) [
                            'DomainName' => (string) $domain_name,
                            'Available' => $is_avail ? 'yes' : 'no',
                            'Price' => $data->price ?? null
                        ]
                    ]
                ];
            }
        }

        // 2. Domain Info Response (fetchRegistrarDomainInfo, getDomainInfo, getDomainContacts)
        if ($this->command === 'domain_info' || isset($data->domain) || isset($data->domain_info)) {
            if (!isset($payload->DomainInfoResponse)) {
                $d = $data->domain_info ?? ($data->domain ?? $data);
                if (is_object($d)) {
                    $payload->DomainInfoResponse = (object) [
                        'DomainInfo' => $this->normalizeDomainObject($d)
                    ];
                }
            }
        }

        // 3. Domain List Response (fetchRegistrarDomains)
        if ($this->command === 'list_domain' || isset($data->domain_list) || isset($data->domains)) {
            if (!isset($payload->ListDomainInfoResponse)) {
                $raw_list = $data->domain_list
                    ?? ($data->domains
                    ?? ($data->domain_info_list
                    ?? ($data->items ?? ($data->results ?? []))));
                if (!is_array($raw_list)) {
                    $raw_list = is_object($raw_list) ? [$raw_list] : [];
                }
                $normalized_list = [];
                foreach ($raw_list as $item) {
                    if (is_object($item)) {
                        $normalized_list[] = $this->normalizeDomainObject($item);
                    }
                }
                $payload->ListDomainInfoResponse = (object) [
                    'MainDomains' => $normalized_list
                ];
            }
        }

        // 4. Domain Nameservers Response (getDomainNameServers)
        if ($this->command === 'get_ns' || $this->command === 'get_nameserver' || isset($data->glue_type) || isset($data->name_server_list)) {
            if (!isset($payload->GetNsResponse)) {
                $nested_domain = $data->domain ?? null;
                $ns_list = $data->nameserver_list
                    ?? ($data->name_server_list
                    ?? ($data->nameservers
                    ?? ($data->name_servers
                    ?? ($data->ns
                    ?? ($nested_domain->nameserver_list
                    ?? ($nested_domain->nameservers
                    ?? ($nested_domain->name_server_list ?? [])))))));
                $ns_content = new stdClass();
                if (!is_array($ns_list)) {
                    $ns_list = is_object($ns_list) ? (array) $ns_list : [];
                }
                if (is_array($ns_list)) {
                    $unique_hosts = [];
                    foreach ($ns_list as $ns_item) {
                        $host = is_object($ns_item)
                            ? ($ns_item->server_name
                                ?? ($ns_item->nameserver
                                ?? ($ns_item->name_server
                                ?? ($ns_item->host ?? ''))))
                            : (string) $ns_item;
                        $host = trim((string) $host);
                        $lower_host = strtolower($host);
                        if ($lower_host !== '' && !isset($unique_hosts[$lower_host])) {
                            $unique_hosts[$lower_host] = $host;
                        }
                    }
                    $idx = 0;
                    foreach ($unique_hosts as $host) {
                        $ns_content->{'Host' . $idx} = $host;
                        $idx++;
                    }
                }
                if (empty((array) $ns_content) && strtoupper((string) ($data->glue_type ?? '')) === 'DNS') {
                    $ns_content->Host0 = 'ns1.dynadot.com';
                    $ns_content->Host1 = 'ns2.dynadot.com';
                    $ns_content->Host2 = 'ns1.dyna-ns.net';
                    $ns_content->Host3 = 'ns2.dyna-ns.net';
                }
                // Deduplicate data->nameserver_list if present
                if (isset($data->nameserver_list) && is_array($data->nameserver_list)) {
                    $deduped_raw_ns = [];
                    $seen_raw_ns = [];
                    foreach ($data->nameserver_list as $raw_ns_item) {
                        $s_name = is_object($raw_ns_item)
                            ? strtolower(trim((string) ($raw_ns_item->server_name ?? ($raw_ns_item->nameserver ?? ''))))
                            : strtolower(trim((string) $raw_ns_item));
                        if ($s_name !== '' && !isset($seen_raw_ns[$s_name])) {
                            $seen_raw_ns[$s_name] = true;
                            $deduped_raw_ns[] = $raw_ns_item;
                        }
                    }
                    $data->nameserver_list = $deduped_raw_ns;
                }
                $payload->GetNsResponse = (object) [
                    'NsContent' => $ns_content
                ];
            }
        }

        // 5. Account Nameserver List Response (addMissingNameserversToAccount & manageHosts)
        if ($this->command === 'server_list' || $this->command === 'nameserver_list' || (isset($data->nameserver_list) && !isset($data->glue_type) && $this->command !== 'get_ns') || isset($data->server_list)) {
            if (!isset($payload->ServerListResponse)) {
                $raw_servers = $data->nameserver_list ?? ($data->server_list ?? ($data->nameservers ?? []));
                if (!is_array($raw_servers)) {
                    $raw_servers = is_object($raw_servers) ? [$raw_servers] : [];
                }
                $server_list = [];
                foreach ($raw_servers as $srv) {
                    if (!is_object($srv)) {
                        continue;
                    }
                    $ips = [];
                    $raw_ips = $srv->ip_list ?? ($srv->ips ?? ($srv->ip ?? []));
                    foreach ((is_array($raw_ips) ? $raw_ips : [$raw_ips]) as $ip_entry) {
                        $ip_val = is_object($ip_entry) ? ($ip_entry->ip ?? ($ip_entry->Ip ?? '')) : (string) $ip_entry;
                        if ($ip_val !== '') {
                            $ips[] = (object) ['Ip' => $ip_val];
                        }
                    }
                    $server_list[] = (object) [
                        'ServerId' => (string) ($srv->server_id ?? ($srv->id ?? ($srv->ServerId ?? ($srv->server_name ?? '')))),
                        'ServerName' => (string) ($srv->server_name ?? ($srv->host ?? ($srv->ServerName ?? ''))),
                        'ServerIps' => $ips
                    ];
                }
                $payload->ServerListResponse = (object) [
                    'ServerList' => $server_list
                ];
            }
        }

        // 6. Contact Get & Create Responses (get_contact & create_contact)
        if ($this->command === 'get_contact' || isset($data->contact)) {
            if (!isset($payload->GetContactResponse)) {
                $c = $data->contact ?? $data;
                if (is_object($c)) {
                    $phone_number = (string) ($c->phone_number ?? '');
                    $phone_cc = (string) ($c->phone_cc ?? ($c->phonecc ?? ($c->PhoneCc ?? '')));
                    $phone_num = (string) ($c->phone_num ?? ($c->phonenum ?? ($c->PhoneNum ?? '')));
                    if ($phone_number !== '' && preg_match('/^\+([^\.]+)\.(.+)$/', $phone_number, $phone_parts)) {
                        $phone_cc = $phone_parts[1];
                        $phone_num = $phone_parts[2];
                    }
                    $payload->GetContactResponse = (object) [
                        'GetContact' => (object) [
                            'ContactId' => (string) ($c->contact_id ?? ($c->id ?? ($c->ContactId ?? ''))),
                            'Name' => (string) ($c->name ?? ($c->Name ?? '')),
                            'Email' => (string) ($c->email ?? ($c->Email ?? '')),
                            'PhoneCc' => $phone_cc,
                            'PhoneNum' => $phone_num,
                            'Organization' => (string) ($c->organization ?? ($c->company ?? ($c->Organization ?? ''))),
                            'Address1' => (string) ($c->address1 ?? ($c->Address1 ?? '')),
                            'Address2' => (string) ($c->address2 ?? ($c->Address2 ?? '')),
                            'City' => (string) ($c->city ?? ($c->City ?? '')),
                            'State' => (string) ($c->state ?? ($c->State ?? '')),
                            'ZipCode' => (string) ($c->zip_code ?? ($c->zip ?? ($c->ZipCode ?? ''))),
                            'Country' => (string) ($c->country ?? ($c->Country ?? ''))
                        ]
                    ];
                }
            }
        }

        if ($this->command === 'create_contact' && !isset($payload->CreateContactResponse)) {
            $c = $data->contact ?? $data;
            $cid = is_object($c) ? ($c->contact_id ?? ($c->id ?? ($c->ContactId ?? null))) : null;
            if ($cid !== null) {
                $payload->CreateContactResponse = (object) [
                    'CreateContactContent' => (object) [
                        'ContactId' => (string) $cid
                    ]
                ];
            }
        }

        // 7. Transfer Auth / EPP Code Response (requestDomainEppCode)
        if ($this->command === 'get_transfer_auth_code' || isset($data->auth_code) || isset($data->transfer_auth_code)) {
            if (!isset($payload->GetTransferAuthCodeResponse)) {
                $auth = $data->auth_code ?? ($data->transfer_auth_code ?? null);
                if ($auth !== null) {
                    $payload->GetTransferAuthCodeResponse = (object) [
                        'AuthCode' => (string) $auth
                    ];
                }
            }
        }

        // 8. TLD Price Response (getUsdPrices)
        if ($this->command === 'tld_price' || isset($data->tld_price_list)) {
            if (!isset($payload->TldPriceResponse)) {
                $raw_prices = $data->tld_price_list ?? ($data->tld_prices ?? []);
                $tld_list = [];
                if (is_array($raw_prices)) {
                    foreach ($raw_prices as $item) {
                        if (!is_object($item)) {
                            continue;
                        }
                        $reg_price = $item->register_price ?? 0;
                        if (empty($reg_price) && !empty($item->all_years_register_price) && is_array($item->all_years_register_price)) {
                            $first_reg = $item->all_years_register_price[0] ?? null;
                            $reg_price = is_object($first_reg) ? ($first_reg->price ?? 0) : $first_reg;
                        }
                        $ren_price = $item->renew_price ?? 0;
                        if (empty($ren_price) && !empty($item->all_years_renew_price) && is_array($item->all_years_renew_price)) {
                            $first_ren = $item->all_years_renew_price[0] ?? null;
                            $ren_price = is_object($first_ren) ? ($first_ren->price ?? 0) : $first_ren;
                        }
                        $tran_price = $item->transfer_price ?? $reg_price;

                        $tld_list[] = (object) [
                            'Tld' => (string) ($item->tld ?? ($item->Tld ?? '')),
                            'Price' => (object) [
                                'Register' => $this->stripCurrencySymbol($reg_price),
                                'Transfer' => $this->stripCurrencySymbol($tran_price),
                                'Renew' => $this->stripCurrencySymbol($ren_price)
                            ],
                            'minDuration' => (int) ($item->min_duration ?? ($item->minDuration ?? 1)),
                            'maxDuration' => (int) ($item->max_duration ?? ($item->maxDuration ?? 10))
                        ];
                    }
                }
                $payload->TldPriceResponse = (object) [
                    'TldPrice' => $tld_list
                ];
            }
        }

        return $payload;
    }

    /**
     * Normalizes a single RESTful v2 domain object into the PascalCase fields
     * expected by dynadot.php.
     *
     * @param stdClass $d
     * @return stdClass
     */
    private function normalizeDomainObject(stdClass $d)
    {
        if (isset($d->DomainInfo)) {
            $d = $d->DomainInfo;
        }

        $whois = $d->whois ?? ($d->contacts ?? ($d->Whois ?? null));
        $reg_id = $d->registrant_contact_id
            ?? ($whois->registrant->contact_id
            ?? ($whois->registrant_contact_id
            ?? ($whois->Registrant->ContactId ?? null)));
        $adm_id = $d->admin_contact_id
            ?? ($whois->admin->contact_id
            ?? ($whois->admin_contact_id
            ?? ($whois->Admin->ContactId ?? $reg_id)));
        $tech_id = $d->tech_contact_id
            ?? ($whois->technical->contact_id
            ?? ($whois->tech_contact_id
            ?? ($whois->Technical->ContactId ?? $reg_id)));
        $bill_id = $d->billing_contact_id
            ?? ($whois->billing->contact_id
            ?? ($whois->billing_contact_id
            ?? ($whois->Billing->ContactId ?? $reg_id)));

        $locked_raw = $d->locked ?? ($d->lock_status ?? ($d->is_locked ?? ($d->Locked ?? 'no')));
        $is_locked = ($locked_raw === true || in_array(strtolower((string) $locked_raw), ['yes', 'true', '1', 'locked', 'lock'], true));

        return (object) [
            'Name' => (string) ($d->domain_name ?? ($d->name ?? ($d->Name ?? ($this->args['domain'] ?? '')))),
            'Registration' => $d->registration_date ?? ($d->registration ?? ($d->Registration ?? null)),
            'Expiration' => $d->expiration_date ?? ($d->expiration ?? ($d->Expiration ?? null)),
            'Status' => (string) ($d->status ?? ($d->Status ?? 'active')),
            'Locked' => $is_locked ? 'yes' : 'no',
            'Privacy' => (string) ($d->privacy ?? ($d->whois_privacy ?? ($d->Privacy ?? 'off'))),
            'Whois' => (object) [
                'Registrant' => (object) ['ContactId' => $reg_id !== null ? (string) $reg_id : ''],
                'Admin' => (object) ['ContactId' => $adm_id !== null ? (string) $adm_id : ''],
                'Technical' => (object) ['ContactId' => $tech_id !== null ? (string) $tech_id : ''],
                'Billing' => (object) ['ContactId' => $bill_id !== null ? (string) $bill_id : '']
            ]
        ];
    }

    /**
     * Strips currency symbols (e.g., "$10.99" -> "10.99") from RESTful v2 price strings.
     *
     * @param mixed $val
     * @return string
     */
    private function stripCurrencySymbol($val)
    {
        if (!is_scalar($val)) {
            return '0';
        }
        $cleaned = preg_replace('/[^0-9.]/', '', (string) $val);
        return $cleaned !== '' ? $cleaned : '0';
    }
}