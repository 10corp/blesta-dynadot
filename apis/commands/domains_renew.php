<?php

/**
 * Dynadot RESTful v2 Domain Renewal Management
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsRenew
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
     * Renews an existing domain name via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/renew
     *
     * @param array $vars Array of renewal parameters:
     *  - domain (string) Domain name to renew
     *  - duration (int, optional) Renewal duration in years (default 1)
     *  - year (int, optional) Current expiration year (auto-resolved by API client if omitted)
     *  - currency (string, optional) Currency code (default 'USD')
     * @return DynadotResponse
     */
    public function renew(array $vars)
    {
        return $this->api->submit('renew', $vars);
    }

    /**
     * Updates domain renewal option (auto or manual) via Dynadot RESTful v2.
     *
     * Endpoint: PUT /restful/v2/domains/{domain}/renew_option
     *
     * @param array $vars Array of parameters:
     *  - domain (string) Domain name
     *  - renew_option (string) 'auto' or 'donot'
     * @return DynadotResponse
     */
    public function setRenewOption(array $vars)
    {
        return $this->api->submit('set_renew_option', $vars);
    }

    /**
     * Restores an expired domain name from redemption status via Dynadot RESTful v2.
     *
     * Endpoint: POST /restful/v2/domains/{domain}/restore
     *
     * @param array $vars Array of parameters:
     *  - domain (string) Domain name to restore
     *  - currency (string, optional) Currency code (default 'USD')
     * @return DynadotResponse
     */
    public function restore(array $vars)
    {
        return $this->api->submit('restore', $vars);
    }

    /**
     * Validates if the requested duration term is within allowed registrar boundaries.
     *
     * @param int|string $term Term duration in years
     * @param int $min Minimum duration in years (default 1)
     * @param int $max Maximum duration in years (default 10)
     * @return bool True if valid
     */
    public function isValidTerm($term, $min = 1, $max = 10)
    {
        $term = (int) $term;
        return ($term >= (int) $min && $term <= (int) $max);
    }
}
