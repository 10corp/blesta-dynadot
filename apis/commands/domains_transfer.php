<?php

/**
 * Dynadot RESTful v2 Domain Transfer Management
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsTransfer
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
     * Submits an inbound domain transfer request via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/transfer_in
     *
     * @param array $vars Array of transfer parameters:
     *  - domain (string) Domain name to transfer
     *  - auth (string) Authorization / EPP code
     *  - duration (int, optional) Transfer renewal duration in years (default 1)
     *  - privacy (string, optional) Privacy level ('full' or 'off')
     *  - registrant_contact (int) Contact ID for registrant
     *  - admin_contact (int, optional) Contact ID for admin
     *  - technical_contact (int, optional) Contact ID for technical
     *  - billing_contact (int, optional) Contact ID for billing
     *  - name_servers (array|string, optional) Custom nameservers
     *  - currency (string, optional) Currency code (default 'USD')
     * @return DynadotResponse
     */
    public function create(array $vars)
    {
        return $this->api->submit('transfer', $vars);
    }

    /**
     * Checks transfer eligibility / availability of a domain name via Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/{domain}/search
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function check(array $vars)
    {
        return $this->api->submit('search', $vars);
    }

    /**
     * Cancels a pending inbound domain transfer via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/cancel_transfer
     *
     * @param array $vars Array with 'domain' key
     * @return DynadotResponse
     */
    public function cancel(array $vars)
    {
        return $this->api->submit('cancel_transfer', $vars);
    }
}
