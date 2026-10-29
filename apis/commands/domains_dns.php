<?php

/**
 * Dynadot RESTful v2 DNS Zone Records & Nameserver Management
 *
 * Encapsulates DNS zone records parsing/building, authoritative nameserver configuration,
 * child nameserver (glue record) registration and deletion via Dynadot RESTful v2.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsDns
{
    /**
     * @var DynadotApi
     */
    private $api;

    /**
     * Sets the API client instance.
     *
     * @param DynadotApi $api The API client
     */
    public function __construct(DynadotApi $api)
    {
        $this->api = $api;
    }

    /**
     * Retrieves DNS zone records for the requested domain via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/records
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getRecords(array $vars)
    {
        return $this->api->submit('get_dns', $vars);
    }

    /**
     * Sets DNS zone records for the requested domain via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/records
     *
     * @param array $vars Array containing DNS record parameters:
     *  - domain (string) Domain name
     *  - dns_main_list (array, optional) Main/apex records
     *  - dns_sub_list (array, optional) Subdomain records
     *  - main_record_type0, main_record0, main_recordx0... (numbered format)
     *  - subdomain0, sub_record_type0, sub_record0, sub_recordx0... (numbered format)
     * @return DynadotResponse
     */
    public function setRecords(array $vars)
    {
        return $this->api->submit('set_dns2', $vars);
    }

    /**
     * Clears DNS or domain service configuration via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/clear_domain_setting
     *
     * @param array $vars Array of parameters:
     *  - domain (string) Domain name
     *  - service_type (string) Service to clear ('nameservers', 'email_forwarding', 'forward', etc.)
     * @return DynadotResponse
     */
    public function clearSetting(array $vars)
    {
        return $this->api->submit('clear_domain_setting', $vars);
    }

    /**
     * Retrieves nameservers for the requested domain via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/nameservers
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getNameservers(array $vars)
    {
        return $this->api->submit('get_ns', $vars);
    }

    /**
     * Updates nameservers for the requested domain via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/nameservers
     *
     * @param array $vars Array with 'domain' and numbered 'ns0', 'ns1'... keys
     * @return DynadotResponse
     */
    public function setNameservers(array $vars)
    {
        return $this->api->submit('set_ns', $vars);
    }

    /**
     * Parses nameserver hosts from Dynadot API response (RESTful v2 and legacy formats).
     *
     * @param mixed $response DynadotResponse or response object
     * @return array List of unique, lowercased nameserver host strings
     */
    public function parseNameserverList($response)
    {
        $result = is_object($response) && method_exists($response, 'response') ? $response->response() : $response;
        if (!is_object($result)) {
            return [];
        }

        $nameservers = [];
        if (isset($result->GetNsResponse->NsContent)) {
            foreach ((array) $result->GetNsResponse->NsContent as $key => $value) {
                if (strpos($key, 'Host') === 0 && !empty($value)) {
                    $nameservers[] = strtolower(trim((string) $value));
                }
            }
        }

        if (empty($nameservers)) {
            $data = $result->data ?? null;
            $domain_data = is_object($data) ? ($data->domain ?? null) : null;
            $raw = $result->nameserver_list
                ?? ($result->nameservers
                ?? ($result->name_server_list
                ?? ($result->name_servers
                ?? ($data->nameserver_list
                ?? ($result->data->nameserver_list
                ?? ($result->data->nameservers
                ?? ($result->data->name_server_list
                ?? ($result->data->name_servers
                ?? ($result->data->ns
                ?? ($domain_data->nameserver_list
                ?? ($domain_data->nameservers ?? ($domain_data->name_server_list ?? []))))))))))));
            if (!is_array($raw)) {
                $raw = is_object($raw) ? (array) $raw : [];
            }
            foreach ($raw as $value) {
                $host = is_object($value)
                    ? ($value->server_name
                        ?? ($value->nameserver
                        ?? ($value->name_server
                        ?? ($value->host ?? ''))))
                    : (string) $value;
                $host = strtolower(trim((string) $host));
                if ($host !== '') {
                    $nameservers[] = $host;
                }
            }
        }

        if (empty($nameservers)) {
            $data = $result->data ?? null;
            $glue_type = strtoupper((string) ($data->glue_type ?? ($result->glue_type ?? '')));
            if ($glue_type === 'DNS') {
                $nameservers = ['ns1.dynadot.com', 'ns2.dynadot.com', 'ns1.dyna-ns.net', 'ns2.dyna-ns.net'];
            }
        }

        return array_values(array_unique(array_filter($nameservers)));
    }

    /**
     * Parses DNS records from both RESTful v2 and legacy response structures into a clean array.
     *
     * @param mixed $response DynadotResponse or decoded response object
     * @return array Array of DNS record arrays with type, subdomain, value, and x keys
     */
    public function parseDnsRecords($response)
    {
        if (is_object($response) && method_exists($response, 'response')) {
            $res = $response->response();
        } else {
            $res = $response;
        }

        if (!is_object($res)) {
            return [];
        }

        $records = [];
        $read = function ($item, $subdomain) {
            $x = $item->record_value2
                ?? ($item->value2
                ?? ($item->distance
                ?? ($item->priority
                ?? ($item->Distance
                ?? ($item->Value2
                ?? ($item->RecordX
                ?? ($item->Priority ?? '')))))));

            $sub = $item->sub_host
                ?? ($item->subhost
                ?? ($item->subdomain
                ?? ($item->Subhost
                ?? ($item->Subdomain ?? $subdomain))));

            return [
                'type' => strtolower((string) ($item->record_type ?? ($item->type ?? ($item->RecordType ?? ($item->Type ?? ''))))),
                'subdomain' => strtolower((string) $sub),
                'value' => (string) ($item->record_value1 ?? ($item->value ?? ($item->record ?? ($item->Value ?? ($item->Record ?? ''))))),
                'x' => is_scalar($x) ? (string) $x : ''
            ];
        };

        // 1. Support RESTful v2 DNS response objects (dns->main_records / dns->sub_records)
        $v2Dns = $res->dns ?? ($res->name_server_settings ?? $res);
        if (is_object($v2Dns)) {
            foreach (['main_records' => false, 'main_domains' => false, 'sub_records' => true, 'sub_domains' => true] as $key => $is_sub) {
                if (!isset($v2Dns->$key)) {
                    continue;
                }
                $items = is_array($v2Dns->$key) ? $v2Dns->$key : [$v2Dns->$key];
                foreach ($items as $item) {
                    if (is_object($item)) {
                        $subhost = $is_sub ? ($item->subdomain ?? ($item->subhost ?? ($item->Subhost ?? ($item->Subdomain ?? '')))) : '';
                        $records[] = $read($item, $subhost);
                    }
                }
            }
        }

        // 2. Glue info lists
        $glue_info = $res->data->glue_info ?? ($res->glue_info ?? null);
        if ($glue_info && is_object($glue_info)) {
            if (!empty($glue_info->dns_main_list) && is_array($glue_info->dns_main_list)) {
                foreach ($glue_info->dns_main_list as $item) {
                    $records[] = $read($item, '');
                }
            }
            if (!empty($glue_info->dns_sub_list) && is_array($glue_info->dns_sub_list)) {
                foreach ($glue_info->dns_sub_list as $item) {
                    $records[] = $read($item, $item->sub_host ?? '');
                }
            }
        }

        // 3. Direct data lists
        $data = $res->data ?? null;
        if ($data && is_object($data)) {
            if (!empty($data->dns_main_list) && is_array($data->dns_main_list)) {
                foreach ($data->dns_main_list as $item) {
                    $records[] = $read($item, '');
                }
            }
            if (!empty($data->dns_sub_list) && is_array($data->dns_sub_list)) {
                foreach ($data->dns_sub_list as $item) {
                    $records[] = $read($item, $item->sub_host ?? '');
                }
            }
        }

        // 4. Normalized GetDnsResponse structures
        $settings = $res->GetDnsResponse->GetDns->NameServerSettings
            ?? ($res->GetDnsResponse->GetDnsContent->NameServerSettings ?? null);
        if (is_object($settings)) {
            foreach (['MainDomains' => false, 'SubDomains' => true] as $key => $is_sub) {
                if (!isset($settings->$key)) {
                    continue;
                }

                $items = is_array($settings->$key) ? $settings->$key : [$settings->$key];
                foreach ($items as $item) {
                    if (is_object($item)) {
                        $records[] = $read($item, $is_sub ? ($item->Subhost ?? ($item->Subdomain ?? '')) : '');
                    }
                }
            }

            for ($i = 0; isset($settings->{'MainRecordType' . $i}); $i++) {
                $records[] = [
                    'type' => strtolower((string) $settings->{'MainRecordType' . $i}),
                    'subdomain' => '',
                    'value' => (string) ($settings->{'MainRecord' . $i} ?? ''),
                    'x' => (string) ($settings->{'MainRecordX' . $i} ?? ($settings->{'MainRecordx' . $i} ?? ''))
                ];
            }
            for ($i = 0; isset($settings->{'SubRecordType' . $i}); $i++) {
                $records[] = [
                    'type' => strtolower((string) $settings->{'SubRecordType' . $i}),
                    'subdomain' => strtolower((string) ($settings->{'Subdomain' . $i} ?? '')),
                    'value' => (string) ($settings->{'SubRecord' . $i} ?? ''),
                    'x' => (string) ($settings->{'SubRecordX' . $i} ?? ($settings->{'SubRecordx' . $i} ?? ''))
                ];
            }
        }

        return array_values(array_filter($records, function ($record) {
            return $record['type'] !== '' && $record['value'] !== '';
        }));
    }

    /**
     * Validates DNS records according to Dynadot constraints.
     *
     * @param array $records Array of record arrays (type, subdomain, value, x)
     * @return array Associative array of error keys, empty if completely valid
     */
    public function validateDnsRecords(array $records)
    {
        $main_types = ['a', 'aaaa', 'cname', 'forward', 'txt', 'mx', 'stealth', 'email'];
        $sub_types = array_merge($main_types, ['srv']);
        $x_types = ['mx', 'forward', 'stealth', 'email'];

        $main = 0;
        $sub = 0;
        $errors = [];

        foreach ($records as $record) {
            if (($record['value'] ?? '') === '') {
                continue;
            }

            $type = strtolower((string) ($record['type'] ?? ''));
            $subdomain = strtolower((string) ($record['subdomain'] ?? ''));
            $is_main = in_array($subdomain, ['', '@'], true);
            $x = (string) ($record['x'] ?? '');

            if (!in_array($type, $is_main ? $main_types : $sub_types)
                || (!$is_main && !preg_match('/^(?:\*|[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?)(?:\.[a-z0-9_](?:[a-z0-9_-]{0,61}[a-z0-9])?)*$/', $subdomain))
                || (in_array($type, $x_types) && $x === '')
            ) {
                $errors['valid'] = ['type' => strtoupper($type), 'value' => $record['value']];
                return $errors;
            }

            if ($is_main) {
                $main++;
            } else {
                $sub++;
            }
        }

        if ($main === 0 && $sub === 0) {
            $errors['empty'] = true;
        } elseif ($main > 20 || $sub > 100) {
            $errors['count'] = true;
        }

        return $errors;
    }

    /**
     * Formats posted form records into numbered DNS arguments for setRecords().
     *
     * @param string $domain Domain name
     * @param array $records Array of record arrays (type, subdomain, value, x)
     * @return array Numbered DNS arguments array for API submission
     */
    public function buildDnsArgs($domain, array $records)
    {
        $args = ['domain' => $domain];
        $main = 0;
        $sub = 0;

        foreach ($records as $record) {
            $val = trim((string) ($record['value'] ?? ''));
            if ($val === '') {
                continue;
            }

            $type = strtolower(trim((string) ($record['type'] ?? '')));
            $subdomain = strtolower(trim((string) ($record['subdomain'] ?? '')));
            $is_main = in_array($subdomain, ['', '@'], true);
            $extra = trim((string) ($record['x'] ?? ''));

            if ($is_main) {
                $args['main_record_type' . $main] = $type;
                $args['main_record' . $main] = $val;
                if ($extra !== '') {
                    $args['main_recordx' . $main] = $extra;
                }
                $main++;
            } else {
                $args['subdomain' . $sub] = $subdomain;
                $args['sub_record_type' . $sub] = $type;
                $args['sub_record' . $sub] = $val;
                if ($extra !== '') {
                    $args['sub_recordx' . $sub] = $extra;
                }
                $sub++;
            }
        }

        return $args;
    }

    /**
     * Registers a new child nameserver (glue record) on Dynadot.
     *
     * @param string $host FQDN hostname (e.g. "ns1.example.com")
     * @param string $ip IPv4 or IPv6 address
     * @return DynadotResponse
     */
    public function registerChildNameserver($host, $ip)
    {
        return $this->api->submit('register_ns', ['host' => $host, 'ip' => $ip]);
    }

    /**
     * Deletes a child nameserver (glue record) on Dynadot.
     *
     * @param string $server_id Server name or ID
     * @return DynadotResponse
     */
    public function deleteChildNameserver($server_id)
    {
        return $this->api->submit('delete_ns', ['server_id' => $server_id]);
    }

    /**
     * Retrieves all registered child nameservers for a given domain suffix.
     *
     * @param string $domain Domain name (e.g. "example.com")
     * @return array Array of objects with 'host' (prefix) and 'ip' properties
     */
    public function getChildNameservers($domain)
    {
        $suffix = '.' . strtolower(trim((string) $domain));
        $response = $this->api->submit('server_list');

        $hosts = [];
        $res = $response->response();
        $servers = $res->ServerListResponse->ServerList ?? ($res->data->nameserver_list ?? []);
        if (!is_array($servers)) {
            $servers = is_object($servers) ? [$servers] : [];
        }

        foreach ($servers as $server) {
            $name = strtolower((string) ($server->ServerName ?? ($server->server_name ?? '')));

            if (strlen($name) > strlen($suffix) && substr($name, -strlen($suffix)) === $suffix) {
                $ips = [];
                $server_ips = $server->ServerIps ?? ($server->ip_list ?? []);
                foreach ((is_array($server_ips) ? $server_ips : [$server_ips]) as $server_ip) {
                    $ip_val = is_object($server_ip) ? ($server_ip->Ip ?? ($server_ip->ip ?? '')) : (string) $server_ip;
                    if (!empty($ip_val)) {
                        $ips[] = (string) $ip_val;
                    }
                }

                $hosts[] = (object) [
                    'host' => substr($name, 0, -strlen($suffix)),
                    'ip' => implode(', ', array_filter($ips))
                ];
            }
        }

        return $hosts;
    }

    /**
     * Checks if any of the provided nameservers need to be added to the Dynadot account.
     *
     * @param array $nameservers Array of nameserver host strings
     */
    public function addMissingNameserversToAccount(array $nameservers)
    {
        $response = $this->api->submit('server_list');
        $existing = [];

        $res = $response->response();
        $servers = $res->ServerListResponse->ServerList ?? ($res->data->nameserver_list ?? []);
        if (!is_array($servers)) {
            $servers = is_object($servers) ? [$servers] : [];
        }

        foreach ($servers as $server) {
            $existing[strtolower((string) ($server->ServerName ?? ($server->server_name ?? '')))] = true;
        }

        foreach ($nameservers as $ns) {
            $host = strtolower(rtrim(trim((string) $ns), '.'));
            if ($host !== '' && !isset($existing[$host])) {
                $this->api->submit('add_ns', ['host' => $host]);
                $existing[$host] = true;
            }
        }
    }
}
