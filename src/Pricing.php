<?php

declare(strict_types=1);

namespace Panel;

final class Pricing
{
    public function __construct(
        private readonly array $domainPricing,
        private readonly array $packages,
        public readonly string $currency,
    ) {
    }

    public function sellPriceForTld(string $tld, ?float $wholesale): ?float
    {
        $tld = strtolower(ltrim($tld, '.'));
        $override = $this->domainPricing['overrides'][$tld] ?? null;
        if ($override !== null) {
            return (float) $override;
        }
        if ($wholesale === null) {
            return null;
        }

        return round($wholesale * (1 + ($this->domainPricing['margin_percent'] ?? 0) / 100), 2);
    }

    public function sellPriceForDomain(string $domain, ?float $wholesale): ?float
    {
        $tld = substr($domain, strpos($domain, '.') + 1);

        return $this->sellPriceForTld($tld, $wholesale);
    }

    public function package(string $id): ?array
    {
        foreach ($this->packages as $package) {
            if ($package['id'] === $id) {
                return $package;
            }
        }

        return null;
    }

    public function packageMonthlyCents(string $id): int
    {
        $package = $this->package($id);

        return $package === null ? 0 : (int) round($package['monthly_price'] * 100);
    }
}
