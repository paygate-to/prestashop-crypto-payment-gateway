<?php
/**
 * PayGate.to crypto API client for the PrestaShop crypto payment module.
 *
 * - API host: the merchant's dedicated API endpoint (e.g. api-yourshop-1a2b….paygate.to) from the
 *   "API Domain" setting, or the shared api.paygate.to when that setting is empty or invalid.
 * - Coin list: the live list from crypto/info.php, flattened into one map keyed by coin id
 *   ("btc", "erc20_usdc", "avax-c_usdc.e"); the API path of a coin is its id with "_" replaced by "/".
 * - Callback signature: every PayGate callback is signed with RSA-2048 / SHA-256 (PKCS#1 v1.5) over
 *   the exact URL PayGate requested. See https://paygate.to/docs/crypto-payment-api/#callback-event
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class PaygateCryptoApi
{
    const DEFAULT_API_HOST = 'api.paygate.to';
    const DEFAULT_CHECKOUT_HOST = 'checkout.paygate.to';

    /** @var string */
    private $host;

    /**
     * @param string $apiDomain "API Domain" setting value (may be empty)
     */
    public function __construct($apiDomain = '')
    {
        $this->host = self::normaliseHost((string) $apiDomain, self::DEFAULT_API_HOST);
    }

    /**
     * Host actually used for API calls.
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * Normalise a domain setting: strips a scheme, path, trailing dot and case.
     * Anything that is not a plain host name falls back to $default.
     *
     * @param string $value
     * @param string $default
     *
     * @return string
     */
    public static function normaliseHost($value, $default)
    {
        $host = strtolower(trim((string) $value));
        $host = (string) preg_replace('#^https?://#', '', $host);
        $host = rtrim(explode('/', $host, 2)[0], '.');

        if ($host === '' || !preg_match('/^(?=.{1,253}$)([a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $host)) {
            return $default;
        }

        return $host;
    }

    /**
     * GET an API path (relative to https://{host}/crypto/) and decode the JSON answer.
     *
     * @param string $path e.g. "info.php" or "erc20/usdc/convert.php"
     * @param array $query
     *
     * @return array|null
     */
    public function get($path, array $query = [])
    {
        $url = 'https://' . $this->host . '/crypto/' . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        return $this->request($url);
    }

    /**
     * POST a JSON body to an API path and decode the JSON answer.
     *
     * @param string $path
     * @param array $payload
     *
     * @return array|null
     */
    public function postJson($path, array $payload)
    {
        return $this->request('https://' . $this->host . '/crypto/' . $path, (string) json_encode($payload));
    }

    /**
     * @param string $url
     * @param string|null $jsonBody POST body, or null for GET
     *
     * @return array|null
     */
    private function request($url, $jsonBody = null)
    {
        $curl = curl_init($url);

        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_USERAGENT => 'PrestaShop-PayGate-Crypto/1.0',
        ];

        if ($jsonBody !== null) {
            $options[CURLOPT_POST] = true;
            $options[CURLOPT_POSTFIELDS] = $jsonBody;
            $options[CURLOPT_HTTPHEADER] = ['Content-Type: application/json'];
        }

        curl_setopt_array($curl, $options);

        $body = curl_exec($curl);
        $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);

        curl_close($curl);

        if (!is_string($body) || $code < 200 || $code >= 300) {
            return null;
        }

        $data = json_decode($body, true);

        return is_array($data) ? $data : null;
    }

    /**
     * Flatten the crypto/info.php answer into a coin map keyed by coin id.
     *
     * Standalone coins (btc, ltc, eth, …) use their ticker as id; chain tokens use "{chain}_{ticker}".
     * Each entry: id, ticker, chain, label, logo, path.
     *
     * @param array $data
     *
     * @return array
     */
    public static function flattenCoins(array $data)
    {
        $map = [];

        foreach ($data as $key => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            if (isset($entry['ticker'])) {
                $coin = self::coinEntry('', $entry);

                if ($coin) {
                    $map[$coin['id']] = $coin;
                }

                continue;
            }

            foreach ($entry as $child) {
                if (is_array($child) && isset($child['ticker'])) {
                    $coin = self::coinEntry((string) $key, $child);

                    if ($coin) {
                        $map[$coin['id']] = $coin;
                    }
                }
            }
        }

        return $map;
    }

    /**
     * @param string $chain
     * @param array $entry
     *
     * @return array|null
     */
    private static function coinEntry($chain, array $entry)
    {
        $ticker = strtolower((string) $entry['ticker']);
        $chain = strtolower($chain);
        $id = $chain !== '' ? $chain . '_' . $ticker : $ticker;

        if (!self::isCoinId($id)) {
            return null;
        }

        $logo = isset($entry['logo']) ? (string) $entry['logo'] : '';

        if (!preg_match('#^https://#i', $logo)) {
            $logo = '';
        }

        return [
            'id' => $id,
            'ticker' => $ticker,
            'chain' => $chain,
            'label' => isset($entry['coin']) ? (string) $entry['coin'] : strtoupper($ticker),
            'logo' => $logo,
            'path' => self::coinPath($id),
        ];
    }

    /**
     * A coin id as used by PayGate: lowercase letters, digits, ".", "-" and one optional "_" separator.
     *
     * @param string $id
     *
     * @return bool
     */
    public static function isCoinId($id)
    {
        $id = (string) $id;

        return (bool) preg_match('/^[a-z0-9.-]{1,32}(_[a-z0-9.-]{1,32})?$/', $id) && strpos($id, '..') === false;
    }

    /**
     * API path for a coin id ("erc20_usdc" -> "erc20/usdc").
     *
     * @param string $id
     *
     * @return string
     */
    public static function coinPath($id)
    {
        return str_replace('_', '/', strtolower((string) $id));
    }

    /**
     * Display label, e.g. "USD Coin (ERC20)".
     *
     * @param array $coin
     *
     * @return string
     */
    public static function coinLabel(array $coin)
    {
        $label = isset($coin['label']) ? (string) $coin['label'] : '';

        if (!empty($coin['chain'])) {
            $label .= ' (' . strtoupper($coin['chain']) . ')';
        }

        return $label;
    }

    /**
     * Random hex token (callback nonce, status page key).
     *
     * @return string
     */
    public static function token()
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * A wallet or pay-in address that is safe to store and display.
     *
     * @param string $address
     *
     * @return bool
     */
    public static function isAddress($address)
    {
        return (bool) preg_match('#^[A-Za-z0-9:._+/=-]{8,200}$#', (string) $address);
    }

    /**
     * A blockchain transaction id as sent in a callback (hex, base58 or base64 depending on the chain).
     *
     * @param string $txid
     *
     * @return bool
     */
    public static function isTxid($txid)
    {
        return (bool) preg_match('#^[A-Za-z0-9:._+/=-]{1,255}$#', (string) $txid);
    }

    /**
     * Parameters of the current callback, parsed from the same raw query string the signature
     * covers. POST data (which Tools::getValue() prefers) is never used.
     *
     * @return array
     */
    public static function callbackParams()
    {
        $params = [];

        parse_str(self::rawQueryString(), $params);

        return $params;
    }

    /**
     * Verify the PayGate signature of the current callback request.
     *
     * The signed string is the callback URL this module registered, up to its "?", plus the raw
     * query string exactly as it arrived. Nothing is rebuilt from parsed parameters and the Host
     * header is never trusted. The raw query comes from REQUEST_URI (the URL as requested), so a
     * web-server rewrite that changes QUERY_STRING cannot break verification.
     *
     * @param string $registeredCallbackUrl callback URL given to PayGate for this cart
     * @param string $keyDir directory holding paygate_callback_public_{key id}.pem
     *
     * @return array ['verified' => bool, 'reason' => string]
     */
    public static function verifyCallback($registeredCallbackUrl, $keyDir)
    {
        $signature = isset($_SERVER['HTTP_X_PAYGATE_SIGNATURE']) ? trim((string) $_SERVER['HTTP_X_PAYGATE_SIGNATURE']) : '';

        if ($signature === '') {
            return ['verified' => false, 'reason' => 'signature_absent'];
        }

        if (!function_exists('openssl_verify')) {
            return ['verified' => false, 'reason' => 'openssl_unavailable'];
        }

        $keyId = isset($_SERVER['HTTP_X_PAYGATE_KEY_ID']) ? trim((string) $_SERVER['HTTP_X_PAYGATE_KEY_ID']) : '';

        if ($keyId === '') {
            $keyId = 'v1';
        }

        // Never let a header reach the filesystem unchecked.
        if (!preg_match('/^[A-Za-z0-9_-]{1,32}$/', $keyId)) {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }

        $pemFile = rtrim($keyDir, '/') . '/paygate_callback_public_' . $keyId . '.pem';

        if (!is_file($pemFile) || !is_readable($pemFile)) {
            return ['verified' => false, 'reason' => 'unknown_key_id'];
        }

        $publicKey = openssl_pkey_get_public((string) file_get_contents($pemFile));

        if ($publicKey === false) {
            return ['verified' => false, 'reason' => 'bad_public_key'];
        }

        $rawSignature = base64_decode($signature, true);

        if ($rawSignature === false || $rawSignature === '') {
            return ['verified' => false, 'reason' => 'signature_not_base64'];
        }

        $registeredCallbackUrl = (string) $registeredCallbackUrl;
        $pos = strpos($registeredCallbackUrl, '?');
        $signed = $pos === false ? $registeredCallbackUrl : substr($registeredCallbackUrl, 0, $pos);

        if (!preg_match('#^https?://[^/?\#\s]+#i', $signed)) {
            return ['verified' => false, 'reason' => 'no_registered_callback'];
        }

        $query = self::rawQueryString();

        if ($query !== '') {
            $signed .= '?' . $query;
        }

        $result = openssl_verify($signed, $rawSignature, $publicKey, OPENSSL_ALGO_SHA256);

        if ($result === 1) {
            return ['verified' => true, 'reason' => ''];
        }

        return ['verified' => false, 'reason' => $result === -1 ? 'openssl_error' : 'signature_mismatch'];
    }

    /**
     * The raw query string of the current request, byte for byte.
     *
     * @return string
     */
    public static function rawQueryString()
    {
        $uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $pos = strpos($uri, '?');

        if ($pos !== false) {
            $query = substr($uri, $pos + 1);
            $hash = strpos($query, '#');

            return $hash === false ? $query : substr($query, 0, $hash);
        }

        return isset($_SERVER['QUERY_STRING']) ? (string) $_SERVER['QUERY_STRING'] : '';
    }
}
