<?php

declare(strict_types=1);

namespace Panel\Api;

interface ApiClient
{
    /** @return list<array{tld: string, price: float, currency: string, transfer_supported: bool}> wholesale prices per year */
    public function listTlds(): array;

    /** @return array{available: ?bool, premium: bool, price: ?float} price = wholesale per year, null when unknown */
    public function checkDomain(string $domain): array;

    /** @return list<array{domain: string, status: string, expires_at: ?string}> */
    public function listDomains(): array;

    /** @return array{domain: string, status: string, expires_at: ?string, nameservers: list<string>, auto_renew: bool, transfer_lock: bool, contact_handle: ?string} */
    public function getDomain(string $domain): array;

    /** @param array{nameservers?: list<string>, years?: int, external_reference?: string} $opts
     *  @return array{success: bool, message: string} */
    public function registerDomain(string $domain, string $contactHandle, array $opts = []): array;

    /** @param array{nameservers?: list<string>, external_reference?: string} $opts
     *  @return array{success: bool, message: string} */
    public function transferDomain(string $domain, string $authCode, string $contactHandle, array $opts = []): array;

    /** @param array{nameservers?: list<string>, auto_renew?: bool, transfer_lock?: bool, whois_contact_handle?: string} $changes
     *  @return array{success: bool, message: string} */
    public function updateDomain(string $domain, array $changes): array;

    public function getAuthCode(string $domain): string;

    /** @return list<array{type: string, name: string, content: string, ttl: int, prio: ?int}> */
    public function getDnsRecords(string $domain): array;

    /** @param list<array{type: string, name: string, content: string, ttl: int, prio: ?int}> $records full replace
     *  @return array{success: bool, message: string} */
    public function saveDnsRecords(string $domain, array $records): array;

    /** @return list<array{handle: string, name: string, email: string}> */
    public function listContacts(): array;

    /** @return array{handle: string, name: string, organization: ?string, address: string, postal_code: string, city: string, country: string, email: string, voice: string} */
    public function getContact(string $handle): array;

    /** @param array{name: string, organization?: ?string, address: string, postal_code: string, city: string, country: string, email: string, voice: string} $data
     *  @return string the new contact handle */
    public function createContact(array $data): string;

    /** @return array{success: bool, message: string} */
    public function updateContact(string $handle, array $data): array;

    /** @return list<array{category_identifier: string, name: string, wholesale_monthly_price: ?float}> */
    public function listPackages(): array;

    /** @param array{external_reference?: string, contact_handle?: string} $opts
     *  @return array{order_id: string} remote order reference */
    public function createHosting(string $domain, string $categoryIdentifier, array $opts = []): array;

    /** @return array{site_id: string, domain: string, status: string, disk_used_bytes: int, disk_quota_bytes: ?int} */
    public function getHosting(string $siteId): array;

    public function getHostingLoginUrl(string $siteId): string;

    /** @return array{status: string, lines: list<array{type: string, status: string, site_id: ?string}>} status: pending|processing|completed|failed */
    public function getOrderStatus(string $remoteOrderId): array;
}
