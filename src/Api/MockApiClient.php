<?php

declare(strict_types=1);

namespace Panel\Api;

final class MockApiClient implements ApiClient
{
    private const TLDS = [
        'com' => 12.50, 'net' => 14.00, 'org' => 13.20,
        'nl' => 6.50, 'eu' => 7.00, 'dev' => 15.90,
    ];

    private const PACKAGES = ['hosting_5gb' => [5, 4.99], 'hosting_20gb' => [20, 14.99], 'hosting_50gb' => [50, 24.99]];

    private readonly MockStore $store;

    public function __construct(string $stateDir)
    {
        $this->store = new MockStore($stateDir);
    }

    public function listTlds(): array
    {
        $out = [];
        foreach (self::TLDS as $tld => $price) {
            $out[] = ['tld' => $tld, 'price' => $price, 'currency' => 'EUR', 'transfer_supported' => true];
        }

        return $out;
    }

    public function checkDomain(string $domain): array
    {
        $tld = substr($domain, strpos($domain, '.') + 1);
        if (!isset(self::TLDS[$tld])) {
            return ['available' => null, 'premium' => false, 'price' => null];
        }

        return [
            'available' => crc32($domain) % 3 !== 0,
            'premium' => crc32($domain) % 17 === 0,
            'price' => self::TLDS[$tld],
        ];
    }

    public function listDomains(): array
    {
        $out = [];
        foreach ($this->store->get('domains', []) as $name) {
            $record = $this->store->get('domain:' . $name);
            if ($record !== null) {
                $out[] = ['domain' => $record['domain'], 'status' => $record['status'], 'expires_at' => $record['expires_at']];
            }
        }

        return $out;
    }

    public function getDomain(string $domain): array
    {
        $record = $this->store->get('domain:' . $domain);
        if ($record === null) {
            throw new ApiException('Domain not found.');
        }

        return $record;
    }

    public function registerDomain(string $domain, string $contactHandle, array $opts = []): array
    {
        $this->storeDomain($domain, $contactHandle, 'active', true, $opts);

        return ['success' => true, 'message' => 'Domain registration started.'];
    }

    public function transferDomain(string $domain, string $authCode, string $contactHandle, array $opts = []): array
    {
        $this->storeDomain($domain, $contactHandle, 'transfer_pending', false, $opts);

        return ['success' => true, 'message' => 'Domain transfer started.'];
    }

    public function updateDomain(string $domain, array $changes): array
    {
        $record = $this->store->get('domain:' . $domain);
        if ($record === null) {
            throw new ApiException('Domain not found.');
        }

        if (array_key_exists('whois_contact_handle', $changes)) {
            $changes['contact_handle'] = $changes['whois_contact_handle'];
            unset($changes['whois_contact_handle']);
        }

        $this->store->set('domain:' . $domain, array_merge($record, $changes));

        return ['success' => true, 'message' => 'Domain updated.'];
    }

    public function getAuthCode(string $domain): string
    {
        return strtoupper(substr(hash('crc32b', 'auth' . $domain), 0, 8));
    }

    public function getDnsRecords(string $domain): array
    {
        return $this->store->get('dns:' . $domain) ?? $this->defaultDnsRecords($domain);
    }

    public function saveDnsRecords(string $domain, array $records): array
    {
        $this->store->set('dns:' . $domain, $records);

        return ['success' => true, 'message' => 'DNS records saved.'];
    }

    public function listContacts(): array
    {
        $out = [];
        foreach ($this->store->get('contacts', []) as $handle) {
            $record = $this->store->get('contact:' . $handle);
            if ($record !== null) {
                $out[] = ['handle' => $record['handle'], 'name' => $record['name'], 'email' => $record['email']];
            }
        }

        return $out;
    }

    public function getContact(string $handle): array
    {
        $record = $this->store->get('contact:' . $handle);
        if ($record === null) {
            throw new ApiException('Contact not found.');
        }

        return $record;
    }

    public function createContact(array $data): string
    {
        $handle = 'MOCK' . strtoupper(base_convert((string) crc32(json_encode($data)), 10, 36));
        $this->store->set('contact:' . $handle, array_merge(['handle' => $handle], $data));

        $index = $this->store->get('contacts', []);
        if (!in_array($handle, $index, true)) {
            $index[] = $handle;
        }
        $this->store->set('contacts', $index);

        return $handle;
    }

    public function updateContact(string $handle, array $data): array
    {
        $record = $this->store->get('contact:' . $handle);
        if ($record === null) {
            throw new ApiException('Contact not found.');
        }

        $this->store->set('contact:' . $handle, array_merge($record, $data));

        return ['success' => true, 'message' => 'Contact updated.'];
    }

    public function listPackages(): array
    {
        $out = [];
        foreach (self::PACKAGES as $categoryIdentifier => [$gb, $price]) {
            $out[] = [
                'category_identifier' => $categoryIdentifier,
                'name' => $gb . ' GB hosting',
                'wholesale_monthly_price' => $price,
            ];
        }

        return $out;
    }

    public function createHosting(string $domain, string $categoryIdentifier, array $opts = []): array
    {
        $id = 'mock-' . time() . '-' . substr(md5($domain), 0, 6);
        $this->store->set('order:' . $id, [
            'domain' => $domain,
            'category_identifier' => $categoryIdentifier,
            'created' => time(),
        ]);

        return ['order_id' => $id];
    }

    public function getHosting(string $siteId): array
    {
        $record = $this->store->get('site:' . $siteId);
        if ($record === null) {
            throw new ApiException('Hosting not found.');
        }

        return $record;
    }

    public function getHostingLoginUrl(string $siteId): string
    {
        return 'https://plesk.example.com/login?token=mock-' . substr(md5($siteId), 0, 12);
    }

    public function getOrderStatus(string $remoteOrderId): array
    {
        $order = $this->store->get('order:' . $remoteOrderId);
        if ($order === null) {
            throw new ApiException('Order not found.');
        }

        $elapsed = time() - $order['created'];
        if ($elapsed < 8) {
            return [
                'status' => 'processing',
                'lines' => [['type' => 'hosting', 'status' => 'processing', 'site_id' => null]],
            ];
        }

        $siteId = 'site-' . substr(md5($order['domain']), 0, 8);
        if ($this->store->get('site:' . $siteId) === null) {
            $gb = self::PACKAGES[$order['category_identifier']][0] ?? 5;
            $quotaBytes = $gb * 1024 * 1024 * 1024;
            $this->store->set('site:' . $siteId, [
                'site_id' => $siteId,
                'domain' => $order['domain'],
                'status' => 'active',
                'disk_used_bytes' => (int) round($quotaBytes * 0.37),
                'disk_quota_bytes' => $quotaBytes,
            ]);
        }

        return [
            'status' => 'completed',
            'lines' => [['type' => 'hosting', 'status' => 'completed', 'site_id' => $siteId]],
        ];
    }

    private function storeDomain(string $domain, string $contactHandle, string $status, bool $transferLock, array $opts): void
    {
        $this->store->set('domain:' . $domain, [
            'domain' => $domain,
            'status' => $status,
            'expires_at' => date('Y-m-d', strtotime('+1 year')),
            'nameservers' => $opts['nameservers'] ?? ['ns1.example-dns.net', 'ns2.example-dns.net'],
            'auto_renew' => true,
            'transfer_lock' => $transferLock,
            'contact_handle' => $contactHandle,
        ]);

        $index = $this->store->get('domains', []);
        if (!in_array($domain, $index, true)) {
            $index[] = $domain;
        }
        $this->store->set('domains', $index);
    }

    private function defaultDnsRecords(string $domain): array
    {
        return [
            ['type' => 'A', 'name' => '@', 'content' => '198.51.100.10', 'ttl' => 3600, 'prio' => null],
            ['type' => 'A', 'name' => 'www', 'content' => '198.51.100.10', 'ttl' => 3600, 'prio' => null],
            ['type' => 'MX', 'name' => '@', 'content' => 'mail.' . $domain, 'ttl' => 3600, 'prio' => 10],
            ['type' => 'TXT', 'name' => '@', 'content' => 'v=spf1 include:_spf.example.com ~all', 'ttl' => 3600, 'prio' => null],
        ];
    }
}
