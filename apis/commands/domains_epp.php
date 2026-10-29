<?php

/**
 * Dynadot RESTful v2 EPP Code & Transfer Lock Management
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsEpp
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
     * Requests the domain transfer authorization (EPP) code via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/transfer_auth_code
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function getAuthCode(array $vars)
    {
        return $this->api->submit('get_transfer_auth_code', $vars);
    }

    /**
     * Locks domain against unauthorized transfers via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/lock_status
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function lockDomain(array $vars)
    {
        return $this->api->submit('lock_domain', array_merge($vars, ['lock_domain' => 'lock']));
    }

    /**
     * Unlocks domain for outgoing transfer via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/lock_status
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function unlockDomain(array $vars)
    {
        return $this->api->submit('lock_domain', array_merge($vars, ['lock_domain' => 'unlock']));
    }
}
