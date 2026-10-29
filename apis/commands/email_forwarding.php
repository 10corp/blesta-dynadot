<?php

/**
 * Dynadot RESTful v2 Email Forwarding Management
 *
 * Encapsulates email alias extraction, formatting, and update operations via Dynadot RESTful v2.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotEmailForwarding
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
     * Retrieves existing email forwarders for the domain via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/records
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getList(array $vars)
    {
        return $this->api->submit('get_dns', $vars);
    }

    /**
     * Sets or updates email forwarding rules via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/email_forwarding
     *
     * @param array $vars Array of forwarding parameters:
     *  - domain (string) Domain name
     *  - email_alias_list (array, optional) Array of ['username' => '...', 'email' => '...']
     *  - username0, exist_email0, username1, exist_email1... (numbered format)
     * @return DynadotResponse
     */
    public function setForwards(array $vars)
    {
        return $this->api->submit('set_email_forward', $vars);
    }

    /**
     * Clears all email forwarders for the domain via Dynadot RESTful v2.
     *
     * Handles both Dynadot DNS mode (by updating DNS zone without email aliases)
     * and standalone email forwarding mode (via clear_domain_setting).
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/clear_domain_setting
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function clearForwards(array $vars)
    {
        $domain = $vars['domain'] ?? '';

        // 1. Check existing domain configuration
        $dns_info = $this->getList(['domain' => $domain]);
        $dns_data = is_object($dns_info) && method_exists($dns_info, 'response') ? $dns_info->response() : null;

        // If the domain is using Dynadot DNS, re-submitting existing DNS records
        // flushes any email forwarding rules without triggering "Can not clear email_forwarding".
        $glue_type = strtoupper((string) ($dns_data->data->glue_info->glue_type ?? ''));
        if ($glue_type === 'DNS') {
            $main_records = $dns_data->dns->main_records ?? ($dns_data->data->glue_info->dns_main_list ?? []);
            $sub_records = $dns_data->dns->sub_records ?? ($dns_data->data->glue_info->dns_sub_list ?? []);

            $dns_args = ['domain' => $domain];
            $idx = 0;
            if (is_array($main_records)) {
                foreach ($main_records as $rec) {
                    if (is_object($rec) && !empty($rec->record_type) && isset($rec->record_value1)) {
                        $dns_args['main_record_type' . $idx] = $rec->record_type;
                        $dns_args['main_record' . $idx] = $rec->record_value1;
                        $idx++;
                    }
                }
            }

            $sub_idx = 0;
            if (is_array($sub_records)) {
                foreach ($sub_records as $rec) {
                    if (is_object($rec) && !empty($rec->record_type) && isset($rec->record_value1)) {
                        $dns_args['sub_record_type' . $sub_idx] = $rec->record_type;
                        $dns_args['sub_record' . $sub_idx] = $rec->record_value1;
                        $dns_args['subdomain' . $sub_idx] = $rec->subhost ?? '';
                        $sub_idx++;
                    }
                }
            }

            if ($idx > 0 || $sub_idx > 0) {
                return $this->api->submit('set_dns2', $dns_args);
            }
        }

        return $this->api->submit('clear_domain_setting', [
            'domain' => $domain,
            'service_type' => 'email_forwarding'
        ]);
    }

    /**
     * Parses active email aliases from API response into a standardized array.
     *
     * @param mixed $response DynadotResponse or decoded response object
     * @return array Array of ['username' => '...', 'exist_email' => '...']
     */
    public function parseEmailForwarding($response)
    {
        $res = is_object($response) && method_exists($response, 'response') ? $response->response() : $response;
        if (!is_object($res)) {
            return [];
        }

        $aliases = $res->email_forwarding->email_alias_list
            ?? ($res->data->glue_info->email_alias_list
            ?? ($res->data->email_alias_list ?? []));

        $forwards = [];
        if (is_array($aliases)) {
            foreach ($aliases as $alias) {
                if (is_object($alias)) {
                    $u = strtolower(trim((string) ($alias->username ?? '')));
                    $e = trim((string) ($alias->email ?? ($alias->exist_email ?? '')));
                    if ($u !== '' || $e !== '') {
                        $forwards[] = [
                            'username' => $u,
                            'exist_email' => $e
                        ];
                    }
                }
            }
        }

        return $forwards;
    }

    /**
     * Formats submitted form post array into numbered forwarder arguments.
     *
     * @param string $domain Domain name
     * @param array $post Submitted post array
     * @return array Array containing 'args' (array), 'errors' (array), 'forwards' (array)
     */
    public function buildEmailForwardArgs($domain, array $post)
    {
        $args = ['domain' => $domain, 'forward_type' => 'forward'];
        $errors = [];
        $forwards = [];
        $cnt = 0;

        foreach ($post as $k => $v) {
            if (preg_match('/^username(\d+)$/', $k, $matches)) {
                $i = $matches[1];
                $username = strtolower(trim((string) ($post['username' . $i] ?? '')));
                $email = trim((string) ($post['exist_email' . $i] ?? ''));
                if ($username === '' && $email === '') {
                    continue;
                }

                // If the user entered a full email address (e.g. "sales@tuhin.com" or "sales@"), extract just the alias prefix
                if (strpos($username, '@') !== false) {
                    $parts = explode('@', $username, 2);
                    $username = trim($parts[0]);
                }

                if (!preg_match('/^[a-z0-9!#$%&\'*+\/=?^_`{|}~.-]{1,64}$/', $username)
                    || !filter_var($email, FILTER_VALIDATE_EMAIL)
                ) {
                    $errors[] = ['username' => $username, 'email' => $email];
                }

                $args['username' . $cnt] = $username;
                $args['exist_email' . $cnt] = $email;
                $forwards[] = ['username' => $username, 'exist_email' => $email];
                $cnt++;
            }
        }

        return [
            'args' => $args,
            'count' => $cnt,
            'errors' => $errors,
            'forwards' => $forwards
        ];
    }
}
