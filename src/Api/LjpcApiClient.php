<?php

declare(strict_types=1);

namespace Panel\Api;

/**
 * Live client for the LJPc reseller API.
 *
 * Every method maps to the endpoints documented in docs/reseller-api.md and applies
 * the response adaptations listed there (envelope unwrap, field renames, pagination,
 * and so on). The transport is injectable for testing; the default is a real curl
 * transport.
 */
final class LjpcApiClient implements ApiClient
{
    private const CONNECT_TIMEOUT = 5;
    private const TOTAL_TIMEOUT = 20;
    // Interactive availability/price lookups use a shorter ceiling so a slow
    // registrar cannot stall the search page; writes and order polling keep the
    // longer TOTAL_TIMEOUT.
    private const INTERACTIVE_TIMEOUT = 8;

    /** @var callable(string, string, ?array, array, int): array{status: int, body: string} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        ?callable $transport = null,
    ) {
        $this->transport = $transport ?? self::curlTransport();
    }

    public function listTlds(): array
    {
        $data = $this->request('GET', '/hosting/v1/tlds');

        return is_array($data) ? array_values($data) : [];
    }

    public function checkDomain(string $domain): array
    {
        // Interactive lookups use the shorter timeout so a slow registrar cannot
        // stall the search page. The API now answers an unsupported/unregistrable
        // TLD with a clean 200 (availability "unknown", price null), so a genuine
        // failure (4xx, 5xx, timeout, transport error) is a real outage and must
        // propagate as ApiException, never be masked as an "unknown" verdict.
        $check = $this->request('GET', '/hosting/v1/domain-names/check', null, ['domain' => $domain], self::INTERACTIVE_TIMEOUT);
        $price = $this->request('GET', '/hosting/v1/domain-names/price', null, ['domain' => $domain], self::INTERACTIVE_TIMEOUT);

        $availability = is_array($check) ? ($check[$domain] ?? null) : null;
        $priceInfo = is_array($price) ? ($price[$domain] ?? null) : null;

        $priceEur = is_array($priceInfo) && isset($priceInfo['price_eur']['amount'])
            ? (float) $priceInfo['price_eur']['amount']
            : null;

        return [
            'available' => $this->mapAvailability($availability),
            'premium' => is_array($priceInfo) ? (bool) ($priceInfo['premium'] ?? false) : false,
            'price' => $priceEur,
        ];
    }

    /**
     * Map the registrar availability string to a tri-state: "available" -> true;
     * a definitive taken/registered allow-list -> false; everything else (unknown/
     * invalid/error/non-string/empty/unrecognized) -> null. Fail safe: an
     * unrecognized status must never be rendered as taken.
     */
    private function mapAvailability(mixed $availability): ?bool
    {
        if (!is_string($availability) || $availability === '') {
            return null;
        }
        if ($availability === 'available') {
            return true;
        }
        if (in_array($availability, ['taken', 'registered', 'unavailable', 'active'], true)) {
            return false;
        }

        return null;
    }

    public function listDomains(): array
    {
        return array_map(
            static fn (array $d): array => [
                'domain' => $d['domain'],
                'status' => $d['status'],
                'expires_at' => $d['expires_at'] ?? null,
            ],
            $this->paginate('/hosting/v1/domain-names', 'domains'),
        );
    }

    public function getDomain(string $domain): array
    {
        $data = $this->request('GET', '/hosting/v1/domain-names/' . rawurlencode($domain));

        return [
            'domain' => $data['domain'] ?? $domain,
            'status' => $data['status'] ?? '',
            'expires_at' => $data['expires_at'] ?? null,
            'nameservers' => $data['nameservers'] ?? [],
            'auto_renew' => (bool) ($data['auto_renew'] ?? false),
            'transfer_lock' => (bool) ($data['transfer_lock'] ?? false),
            'contact_handle' => $data['contact_handle'] ?? null,
        ];
    }

    public function registerDomain(string $domain, string $contactHandle, array $opts = []): array
    {
        $body = ['domain' => $domain, 'contact_handle' => $contactHandle];
        if (isset($opts['years'])) {
            $body['years'] = (int) $opts['years'];
        }
        if (isset($opts['nameservers'])) {
            $body['nameservers'] = $opts['nameservers'];
        }
        if (isset($opts['external_reference'])) {
            $body['external_reference'] = $opts['external_reference'];
        }

        return $this->writeResult($this->send('POST', '/hosting/v1/domain-names', $body));
    }

    public function transferDomain(string $domain, string $authCode, string $contactHandle, array $opts = []): array
    {
        $body = ['auth_code' => $authCode, 'contact_handle' => $contactHandle];
        if (isset($opts['nameservers'])) {
            $body['nameservers'] = $opts['nameservers'];
        }
        if (isset($opts['external_reference'])) {
            $body['external_reference'] = $opts['external_reference'];
        }

        return $this->writeResult(
            $this->send('POST', '/hosting/v1/domain-names/' . rawurlencode($domain) . '/transfer', $body),
        );
    }

    public function updateDomain(string $domain, array $changes): array
    {
        $res = $this->send('PATCH', '/hosting/v1/domain-names/' . rawurlencode($domain), $changes);

        // A no-op edit is not a real failure; surface it as a success.
        if ($res['status'] === 422 && is_array($res['json']) && ($res['json']['message'] ?? '') === 'No changes to apply') {
            return ['success' => true, 'message' => 'No changes to apply'];
        }

        return $this->writeResult($res);
    }

    public function getAuthCode(string $domain): string
    {
        $data = $this->request('GET', '/hosting/v1/domain-names/' . rawurlencode($domain) . '/authcode');

        return (string) ($data['auth_code'] ?? '');
    }

    public function getDnsRecords(string $domain): array
    {
        $res = $this->send('GET', '/hosting/v1/dns/zones/' . rawurlencode($domain) . '/records');
        if ($res['status'] === 404) {
            return [];
        }

        $data = $this->unwrap($res);
        $records = is_array($data) && isset($data['records']) && is_array($data['records']) ? $data['records'] : [];

        return array_map(
            static fn (array $r): array => [
                'type' => $r['type'],
                'name' => $r['name'],
                'content' => $r['content'],
                'ttl' => (int) $r['ttl'],
                'prio' => isset($r['prio']) ? (int) $r['prio'] : null,
            ],
            $records,
        );
    }

    public function saveDnsRecords(string $domain, array $records): array
    {
        return $this->writeResult(
            $this->send('PUT', '/hosting/v1/dns/zones/' . rawurlencode($domain) . '/records', ['records' => array_values($records)]),
        );
    }

    public function listContacts(): array
    {
        return array_map(
            static fn (array $c): array => [
                'handle' => $c['handle'],
                'name' => $c['name'],
                'email' => $c['email'],
            ],
            $this->paginate('/hosting/v1/contacts', 'contacts'),
        );
    }

    public function getContact(string $handle): array
    {
        $data = $this->request('GET', '/hosting/v1/contacts/' . rawurlencode($handle));
        $addressLine = $data['addressLine'] ?? [];

        return [
            'handle' => $data['handle'] ?? $handle,
            'name' => $data['name'] ?? '',
            'organization' => $data['organization'] ?? null,
            'address' => is_array($addressLine) ? ($addressLine[0] ?? '') : (string) $addressLine,
            'postal_code' => $data['postalCode'] ?? '',
            'city' => $data['city'] ?? '',
            'country' => $data['country'] ?? '',
            'email' => $data['email'] ?? '',
            'voice' => $data['voice'] ?? '',
        ];
    }

    public function createContact(array $data): string
    {
        $result = $this->request('POST', '/hosting/v1/contacts', $data);

        return (string) ($result['handle'] ?? '');
    }

    public function updateContact(string $handle, array $data): array
    {
        return $this->writeResult($this->send('PATCH', '/hosting/v1/contacts/' . rawurlencode($handle), $data));
    }

    public function listPackages(): array
    {
        $out = [];
        foreach ($this->fetchProducts() as $product) {
            $identifier = (string) ($product['category_identifier'] ?? '');
            if (!$this->isHostingPackage($identifier)) {
                continue;
            }
            $monthly = $product['monthly_price'] ?? null;
            $out[] = [
                'category_identifier' => $identifier,
                'name' => $product['name'] ?? '',
                'wholesale_monthly_price' => $monthly === null ? null : (float) $monthly,
            ];
        }

        return $out;
    }

    public function createHosting(string $domain, string $categoryIdentifier, array $opts = []): array
    {
        $product = null;
        foreach ($this->fetchProducts() as $candidate) {
            if (($candidate['category_identifier'] ?? null) === $categoryIdentifier) {
                $product = $candidate;
                break;
            }
        }
        if ($product === null) {
            throw new ApiException('The selected hosting package is not available.');
        }

        $cartItem = [
            'type' => 'hosting',
            'product_uuid' => $product['uuid'],
            'domain' => $domain,
        ];
        // client_choice products require a billing period; default to monthly for the panel.
        if (($product['billing_period_type'] ?? '') === 'client_choice') {
            $cartItem['billing_period'] = 'monthly';
        }

        $body = [
            'summary' => 'Hosting order for ' . $domain,
            'order_lines' => [
                ['description' => ($product['name'] ?? 'Hosting') . ' - ' . $domain, 'cart_item' => $cartItem],
            ],
        ];
        if (isset($opts['external_reference'])) {
            $body['meta'] = ['external_reference' => $opts['external_reference']];
        }

        $data = $this->request('POST', '/v1/orders', $body);
        $orderId = is_array($data) && isset($data['order']['id']) ? $data['order']['id'] : null;
        if ($orderId === null) {
            throw new ApiException('The hosting order could not be created.');
        }

        return ['order_id' => (string) $orderId];
    }

    public function getHosting(string $siteId): array
    {
        $data = $this->request('GET', '/hosting/v1/sites/' . rawurlencode($siteId));

        return [
            'site_id' => (string) ($data['id'] ?? $siteId),
            'domain' => $data['domain'] ?? '',
            'status' => $data['status'] ?? '',
            'disk_used_bytes' => $data['disk_used_bytes'] ?? null,
            'disk_quota_bytes' => $data['disk_quota_bytes'] ?? null,
        ];
    }

    public function getHostingLoginUrl(string $siteId): string
    {
        $res = $this->send('GET', '/hosting/v1/sites/' . rawurlencode($siteId) . '/login');
        // 204 = no single-use SSO URL available right now. There is no usable string to
        // return, so make the failure explicit rather than handing back an empty URL.
        if ($res['status'] === 204) {
            throw new ApiException('A login link is not available for this site right now, please try again.');
        }

        $data = $this->unwrap($res);

        return (string) ($data['url'] ?? '');
    }

    public function getOrderStatus(string $remoteOrderId): array
    {
        $data = $this->request('GET', '/v1/orders/' . rawurlencode($remoteOrderId));
        $lines = is_array($data) && isset($data['provisioning']['lines']) && is_array($data['provisioning']['lines'])
            ? $data['provisioning']['lines']
            : [];

        return [
            'status' => $data['simple_status'] ?? '',
            'lines' => array_map(
                static function (array $line): array {
                    $state = (string) ($line['state'] ?? '');
                    $siteId = $line['site_id'] ?? null;

                    return [
                        'type' => $line['type'] ?? '',
                        'status' => $state === 'done' ? 'completed' : $state,
                        'site_id' => $siteId === null ? null : (string) $siteId,
                    ];
                },
                $lines,
            ),
        ];
    }

    /**
     * Envelope-aware call: returns the unwrapped `.data`, throws on any failure.
     *
     * @param array<string, mixed> $query
     */
    private function request(string $method, string $path, ?array $jsonBody = null, array $query = [], int $timeout = self::TOTAL_TIMEOUT): mixed
    {
        return $this->unwrap($this->send($method, $path, $jsonBody, $query, $timeout));
    }

    /**
     * Unwrap a standard `{success, jti, data}` envelope, mapping any failure to a
     * user-safe ApiException carrying the envelope `.message`.
     *
     * @param array{status: int, json: mixed} $res
     */
    private function unwrap(array $res): mixed
    {
        $json = $res['json'];
        if ($res['status'] >= 200 && $res['status'] < 300 && is_array($json) && ($json['success'] ?? false) === true) {
            return $json['data'] ?? null;
        }

        // Carry the HTTP status as the exception code so callers can distinguish a
        // 4xx (real caller error) from a 5xx (upstream failure). Message stays user-safe.
        throw new ApiException($this->errorMessage($json), $res['status']);
    }

    /**
     * Synthesize the `{success, message}` write-result shape the interface expects.
     * Domain writes carry their human message at `.data.message`; DNS/contact writes
     * carry none. Any non-success envelope is surfaced as an ApiException.
     *
     * @param  array{status: int, json: mixed} $res
     * @return array{success: bool, message: string}
     */
    private function writeResult(array $res): array
    {
        $json = $res['json'];
        $ok = $res['status'] >= 200 && $res['status'] < 300 && is_array($json) && ($json['success'] ?? false) === true;
        if (!$ok) {
            throw new ApiException($this->errorMessage($json), $res['status']);
        }

        $data = $json['data'] ?? null;
        $message = is_array($data) && isset($data['message']) && is_string($data['message']) ? $data['message'] : '';

        return ['success' => true, 'message' => $message];
    }

    /**
     * Page through a paginated `.data.<itemsKey>` collection until `total` is covered.
     *
     * @return list<array<string, mixed>>
     */
    private function paginate(string $path, string $itemsKey): array
    {
        $items = [];
        $page = 1;
        do {
            $data = $this->request('GET', $path, null, ['page' => $page, 'per_page' => 100]);
            $pageItems = is_array($data) && isset($data[$itemsKey]) && is_array($data[$itemsKey]) ? $data[$itemsKey] : [];
            foreach ($pageItems as $item) {
                $items[] = $item;
            }
            $total = is_array($data) ? (int) ($data['total'] ?? count($items)) : count($items);
            $page++;
        } while ($pageItems !== [] && count($items) < $total);

        return $items;
    }

    /**
     * Fetch the raw (envelope-less) products catalog.
     *
     * @return list<array<string, mixed>>
     */
    private function fetchProducts(): array
    {
        $res = $this->send('GET', '/v1/products');
        if ($res['status'] < 200 || $res['status'] >= 300) {
            throw new ApiException('The product catalog is temporarily unavailable, please try again.');
        }

        $json = $res['json'];

        return is_array($json) && isset($json['products']) && is_array($json['products']) ? $json['products'] : [];
    }

    private function isHostingPackage(string $identifier): bool
    {
        return $identifier === 'hosting_forward'
            || $identifier === 'hosting_dns'
            || preg_match('/^hosting_\d+gb$/', $identifier) === 1;
    }

    /**
     * Extract a user-safe error message from a failed envelope, never leaking internals.
     */
    private function errorMessage(mixed $json): string
    {
        if (is_array($json) && isset($json['message']) && is_string($json['message']) && $json['message'] !== '') {
            return $json['message'];
        }

        return 'The hosting service returned an unexpected error, please try again.';
    }

    /**
     * Perform the HTTP call and decode the JSON body.
     *
     * @param  array<string, mixed> $query
     * @param  int                  $timeout Total request timeout in seconds.
     * @return array{status: int, json: mixed}
     */
    private function send(string $method, string $path, ?array $jsonBody = null, array $query = [], int $timeout = self::TOTAL_TIMEOUT): array
    {
        $url = rtrim($this->baseUrl, '/') . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query);
        }

        $response = ($this->transport)($method, $url, $jsonBody, ['X-API-Key' => $this->apiKey], $timeout);
        $body = $response['body'];

        return [
            'status' => $response['status'],
            'json' => $body === '' ? null : json_decode($body, true),
        ];
    }

    /**
     * Build the default curl transport. No mutable static state (FrankenPHP-safe).
     *
     * @return callable(string, string, ?array, array, int): array{status: int, body: string}
     */
    private static function curlTransport(): callable
    {
        return static function (string $method, string $url, ?array $jsonBody, array $headers, int $timeout = self::TOTAL_TIMEOUT): array {
            $curlHeaders = [];
            foreach ($headers as $name => $value) {
                $curlHeaders[] = $name . ': ' . $value;
            }

            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $url);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout > 0 ? $timeout : self::TOTAL_TIMEOUT);
            if ($jsonBody !== null) {
                $curlHeaders[] = 'Content-Type: application/json';
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($jsonBody, JSON_THROW_ON_ERROR));
            }
            curl_setopt($ch, CURLOPT_HTTPHEADER, $curlHeaders);

            $body = curl_exec($ch);
            $failed = $body === false || curl_errno($ch) !== 0;
            $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($failed) {
                // Never expose curl error text, the URL, or the key.
                throw new ApiException('The hosting service is unreachable, please try again.');
            }

            return ['status' => $status, 'body' => (string) $body];
        };
    }
}
