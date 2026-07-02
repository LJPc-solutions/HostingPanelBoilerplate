<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\Api\ApiException;
use Panel\App;
use Panel\Response;

final class SearchController
{
    public function __construct(private readonly App $app)
    {
    }

    public function search(array $params): Response
    {
        if (!array_key_exists('q', $_GET)) {
            return $this->render('', []);
        }

        $q = strtolower(trim((string) $_GET['q']));
        $sld = $this->sld($q);

        if ($q === '' || str_contains($q, ' ') || !valid_sld($sld)) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Please enter a valid domain or name to search for.'];

            return $this->render($q, []);
        }

        // A single unknown-TLD row no longer throws (checkDomain returns a null
        // verdict for it); only a real outage (5xx, timeout, transport error)
        // raises ApiException. Degrade the whole page to a friendly flash instead
        // of a 500, and show no partial rows. array_map either builds every row or
        // aborts, so a mid-search failure leaves no half-filled result set.
        try {
            $results = array_map(
                fn (string $domain): array => $this->checkRow($domain),
                $this->candidateDomains($q, $sld),
            );
        } catch (ApiException) {
            $_SESSION['flash'] = ['type' => 'error', 'message' => 'Domain search is temporarily unavailable, please try again in a moment.'];

            return $this->render($q, []);
        }

        return $this->render($q, $results);
    }

    /**
     * The exact set of domains a search resolves, bounded by the configured
     * `search_tlds`. A bare name expands to that name under every configured TLD;
     * a dotted query resolves the exact domain first, then the same name under
     * every configured TLD except the one already covered by the exact match.
     * This is what stops a single search from fanning out across the full (~1400
     * entry) sellable-TLD catalog, which would be two API calls per TLD.
     *
     * @return list<string>
     */
    private function candidateDomains(string $q, string $sld): array
    {
        $tlds = $this->app->config['search_tlds'] ?? [];

        if (str_contains($q, '.')) {
            $domains = [$q];
            foreach ($tlds as $tld) {
                $domain = $sld . '.' . $tld;
                if ($domain !== $q) {
                    $domains[] = $domain;
                }
            }

            return $domains;
        }

        return array_map(static fn (string $tld): string => $q . '.' . $tld, $tlds);
    }

    private function render(string $query, array $results): Response
    {
        return Response::html($this->app->view->render('search', [
            'title' => 'Domain search',
            'query' => $query,
            'results' => $results,
        ]));
    }

    private function sld(string $q): string
    {
        $dot = strpos($q, '.');

        return $dot === false ? $q : substr($q, 0, $dot);
    }

    /** @return array{domain: string, available: ?bool, premium: bool, sell_price: ?float} */
    private function checkRow(string $domain): array
    {
        $check = $this->app->api->checkDomain($domain);

        return [
            'domain' => $domain,
            'available' => $check['available'],
            'premium' => $check['premium'],
            'sell_price' => $this->app->pricing->sellPriceForDomain($domain, $check['price']),
        ];
    }
}
