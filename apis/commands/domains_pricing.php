<?php

/**
 * Dynadot RESTful v2 Domain Pricing Management
 *
 * Encapsulates all TLD pricing retrieval, pagination, caching,
 * multi-currency conversion, and filtering directly from Dynadot's RESTful v2 API.
 *
 * @package 10corp
 * @subpackage 10corp.modules.dynadot.apis.commands
 * @copyright Copyright (c) 2026, 10Corp
 * @license https://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 * @link https://www.10corp.com
 */
class DynadotDomainsPricing
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
     * Retrieves a page of TLD pricing from Dynadot RESTful v2.
     *
     * Endpoint: GET /restful/v2/domains/get_tld_price
     *
     * @param array $vars Array of parameters:
     *  - currency (string, optional) 3-letter currency code (e.g., 'USD', 'EUR'). Default 'USD'.
     *  - page (int, optional) Page index. Default 1.
     *  - page_size (int, optional) Number of items per page. Default 100.
     * @return DynadotResponse
     */
    public function getTldPrice(array $vars = [])
    {
        return $this->api->submit('domain_get_tld_price', $vars);
    }

    /**
     * Retrieves the entire TLD price catalog by paginating through all available pages.
     *
     * @param string $currency Currency code (e.g., 'USD', 'EUR', 'GBP')
     * @param int $page_size Number of items per page (default 100)
     * @return array Array of raw TLD price items from Dynadot RESTful v2
     */
    public function getAllTldPrices($currency = 'USD', $page_size = 100)
    {
        $all_prices = [];
        $page = 1;

        while (true) {
            $response = $this->getTldPrice([
                'currency' => strtoupper((string) $currency),
                'page' => $page,
                'page_size' => $page_size
            ]);

            if ($response->status() !== 'success') {
                break;
            }

            $data = $response->response()->data ?? null;
            $items = $data->tld_price_list ?? [];
            if (empty($items) || !is_array($items)) {
                break;
            }

            foreach ($items as $item) {
                if (is_object($item)) {
                    $all_prices[] = $item;
                }
            }

            $total_count = (int) ($data->total_count ?? 0);
            if ($total_count > 0 && count($all_prices) >= $total_count) {
                break;
            }

            if (count($items) < $page_size) {
                break;
            }

            $page++;
        }

        return $all_prices;
    }

    /**
     * Parses a scalar or string price value into a clean float.
     *
     * @param mixed $price Raw price value to parse.
     * @return float Numeric price as a float.
     */
    public function parsePriceValue($price)
    {
        return is_scalar($price) ? (float) str_replace(',', '', (string) $price) : 0.0;
    }

    /**
     * Fetches and caches USD TLD prices via RESTful v2 GET /restful/v2/domains/get_tld_price.
     *
     * @param stdClass $module_row Dynadot module row object
     * @param int|null $company_id Current Blesta company ID
     * @param bool $force_refresh True to bypass the cache and re-fetch from Dynadot
     * @return array Associative array of TLD => price data (registration, transfer, renew, min, max)
     */
    public function fetchCachedUsdPrices($module_row, $company_id = 1, $force_refresh = false)
    {
        if (empty($module_row) || empty($module_row->meta->key)) {
            return [];
        }

        $sandbox = (isset($module_row->meta->sandbox) && $module_row->meta->sandbox == 'true');
        $cache_key = 'tld_prices_' . ($module_row->id ?? 'default') . '_' . ($sandbox ? 'sandbox' : 'live');
        $cache_dir = $company_id . DS . 'modules' . DS . 'dynadot' . DS;

        if (!$force_refresh) {
            $cache = Cache::fetchCache($cache_key, $cache_dir);
            if ($cache) {
                $cached = safe_unserialize(base64_decode($cache));
                if (!is_array($cached)) {
                    $cached = json_decode($cache, true);
                }
                if (is_array($cached) && !empty($cached)) {
                    return $cached;
                }
            }
        }

        $prices = [];
        $page_size = 100;

        for ($page = 1; $page <= 100; $page++) {
            $response = $this->getTldPrice([
                'currency' => 'usd',
                'page_index' => $page,
                'count_per_page' => $page_size
            ]);
            $result = $response->response();

            if ($response->status() != 'success') {
                break;
            }

            $items = $result->TldPriceResponse->TldPrice ?? [];
            if (!is_array($items)) {
                $items = [$items];
            }

            $new_tlds = 0;
            foreach ($items as $item) {
                if (!isset($item->Tld) || !isset($item->Price)) {
                    continue;
                }

                $tld = '.' . ltrim(strtolower(trim((string) $item->Tld)), '.');
                if (isset($prices[$tld])) {
                    continue;
                }

                $new_tlds++;
                $prices[$tld] = [
                    'registration' => $this->parsePriceValue($item->Price->Register ?? 0),
                    'transfer' => $this->parsePriceValue($item->Price->Transfer ?? 0),
                    'renew' => $this->parsePriceValue($item->Price->Renew ?? 0),
                    'min' => max(1, (int) ($item->minDuration ?? 1)),
                    'max' => min(10, max(1, (int) ($item->maxDuration ?? 10)))
                ];
            }

            if ($new_tlds == 0 || count($items) < $page_size) {
                break;
            }
        }

        if (!empty($prices) && Configure::get('Caching.on') && is_writable(CACHEDIR)) {
            try {
                $ttl = strtotime(Configure::get('Blesta.cache_length')) - time();
                Cache::writeCache($cache_key, base64_encode(serialize($prices)), $ttl, $cache_dir);
                Cache::writeCache('tlds_prices', base64_encode(serialize($prices)), $ttl, $cache_dir);
                Cache::writeCache('tlds', base64_encode(serialize(array_keys($prices))), $ttl, $cache_dir);
            } catch (\Throwable $e) {
                Configure::set('Caching.on', false);
            }
        }

        return $prices;
    }

    /**
     * Converts cached USD prices to unit pricing objects for Blesta's getPrices().
     *
     * @param array $tlds Array of TLD prices in USD
     * @param array $currencies Array of currency objects
     * @param array $filters Optional filters
     * @param object|null $currency_converter Currencies model
     * @param int $company_id Company ID
     * @return array Unit pricing array
     */
    public function getUnitPrices(array $tlds, array $currencies, array $filters = [], $currency_converter = null, $company_id = 1)
    {
        $filter_tlds = null;
        if (!empty($filters['tlds'])) {
            $filter_tlds = [];
            foreach ((array) $filters['tlds'] as $ft) {
                $cleaned = strtolower(trim((string) $ft));
                $filter_tlds['.' . ltrim($cleaned, '.')] = true;
                $filter_tlds[ltrim($cleaned, '.')] = true;
            }
        }

        $pricing = [];

        foreach ($tlds as $tld => $tld_pricing) {
            $tld_dot = '.' . ltrim(strtolower(trim($tld)), '.');
            $tld_bare = ltrim($tld_dot, '.');

            if ($filter_tlds !== null && !isset($filter_tlds[$tld_dot]) && !isset($filter_tlds[$tld_bare])) {
                continue;
            }

            $tld_pricing = (array) $tld_pricing;
            $reg_usd = is_scalar($tld_pricing['registration'] ?? null) ? (float) $tld_pricing['registration'] : 0.0;
            $tran_usd = is_scalar($tld_pricing['transfer'] ?? null) ? (float) $tld_pricing['transfer'] : 0.0;
            $ren_usd = is_scalar($tld_pricing['renew'] ?? null) ? (float) $tld_pricing['renew'] : 0.0;

            foreach ($currencies as $currency) {
                if (isset($filters['currencies']) && !in_array($currency->code, (array) $filters['currencies'])) {
                    continue;
                }

                $curr_code = $currency->code;
                $pricing[$tld_dot][$curr_code] = (object) [
                    'registration' => ($curr_code === 'USD' || !$currency_converter)
                        ? $reg_usd
                        : (float) $currency_converter->convert($reg_usd, 'USD', $curr_code, $company_id),
                    'transfer' => ($curr_code === 'USD' || !$currency_converter)
                        ? $tran_usd
                        : (float) $currency_converter->convert($tran_usd, 'USD', $curr_code, $company_id),
                    'renew' => ($curr_code === 'USD' || !$currency_converter)
                        ? $ren_usd
                        : (float) $currency_converter->convert($ren_usd, 'USD', $curr_code, $company_id),
                    'min' => max(1, (int) ($tld_pricing['min'] ?? 1)),
                    'max' => min(10, max(1, (int) ($tld_pricing['max'] ?? 10)))
                ];
            }
        }

        return $pricing;
    }

    /**
     * Converts cached USD prices to multi-currency pricing arrays filtered by criteria.
     *
     * @param array $tlds Array of TLD prices in USD
     * @param array $currencies Array of currency objects from Blesta
     * @param array $filters Optional filters (tlds, currencies, terms)
     * @param object|null $currency_converter Currencies model instance with convert() method
     * @param int $company_id Company ID
     * @return array Multi-currency pricing array keyed by TLD then currency code
     */
    public function convertTldPrices(array $tlds, array $currencies, array $filters = [], $currency_converter = null, $company_id = 1)
    {
        // Normalize filter TLDs if provided
        $filter_tlds = null;
        if (!empty($filters['tlds'])) {
            $filter_tlds = [];
            foreach ((array) $filters['tlds'] as $ft) {
                $cleaned = strtolower(trim((string) $ft));
                $filter_tlds['.' . ltrim($cleaned, '.')] = true;
                $filter_tlds[ltrim($cleaned, '.')] = true;
            }
        }

        $pricing = [];

        foreach ($tlds as $tld => $tld_pricing) {
            $tld_dot = '.' . ltrim(strtolower(trim($tld)), '.');
            $tld_bare = ltrim($tld_dot, '.');

            if ($filter_tlds !== null && !isset($filter_tlds[$tld_dot]) && !isset($filter_tlds[$tld_bare])) {
                continue;
            }

            $tld_pricing = (array) $tld_pricing;
            $reg_usd = is_scalar($tld_pricing['registration'] ?? null) ? (float) $tld_pricing['registration'] : 0.0;
            $tran_usd = is_scalar($tld_pricing['transfer'] ?? null) ? (float) $tld_pricing['transfer'] : 0.0;
            $ren_usd = is_scalar($tld_pricing['renew'] ?? null) ? (float) $tld_pricing['renew'] : 0.0;

            foreach ($currencies as $currency) {
                if (isset($filters['currencies']) && !in_array($currency->code, (array) $filters['currencies'])) {
                    continue;
                }

                $curr_code = $currency->code;
                $currency_pricing = [];

                $terms = isset($filters['terms']) ? (array) $filters['terms'] : range(1, (int) ($tld_pricing['max'] ?? 10));

                foreach ($terms as $term) {
                    $term = (int) $term;
                    if ($term < 1 || $term > 10) {
                        continue;
                    }

                    $reg_price = round($reg_usd * $term, 2);
                    $ren_price = round($ren_usd * $term, 2);
                    $tran_price = round($tran_usd * $term, 2);

                    if ($curr_code !== 'USD' && $currency_converter) {
                        $reg_price = round((float) $currency_converter->convert($reg_price, 'USD', $curr_code, $company_id), 2);
                        $ren_price = round((float) $currency_converter->convert($ren_price, 'USD', $curr_code, $company_id), 2);
                        $tran_price = round((float) $currency_converter->convert($tran_price, 'USD', $curr_code, $company_id), 2);
                    }

                    $currency_pricing[$term] = [
                        'register' => $reg_price,
                        'transfer' => $tran_price,
                        'renew' => $ren_price
                    ];
                }

                if (!empty($currency_pricing)) {
                    $pricing[$tld_dot][$curr_code] = $currency_pricing;
                }
            }
        }

        return $pricing;
    }
}
