<?php

/**
 * Dynadot RESTful v2 WHOIS Privacy / ID Protection Management
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsPrivacy
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
     * Toggles WHOIS privacy (ID Protection) on or off via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/privacy
     *
     * @param array $vars Array of privacy parameters:
     *  - domain (string) Domain name
     *  - option (string, optional) 'full' or 'off'
     *  - privacy_level (string, optional) 'full' or 'off'
     * @return DynadotResponse
     */
    public function set(array $vars)
    {
        return $this->api->submit('set_privacy', $vars);
    }

    /**
     * Retrieves domain information including privacy status via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function get(array $vars)
    {
        return $this->api->submit('domain_info', $vars);
    }
}
