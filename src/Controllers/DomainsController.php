<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\Api\ApiException;
use Panel\App;
use Panel\Response;

final class DomainsController
{
    private const NAMESERVER_PATTERN = '/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/i';

    public function __construct(private readonly App $app)
    {
    }

    public function index(array $params): Response
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM domains WHERE user_id = ? ORDER BY domain');
        $stmt->execute([$this->user()['id']]);

        $domains = array_map(fn (array $row) => $this->withLiveDetails($row), $stmt->fetchAll());

        return Response::html($this->app->view->render('domains/index', [
            'title' => 'Domains',
            'domains' => $domains,
        ]));
    }

    public function show(array $params): Response
    {
        $row = $this->ownedDomain($params['domain']);
        if ($row === null) {
            return $this->notFound();
        }

        try {
            $live = $this->app->api->getDomain($row['domain']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], 'error', $e->getMessage(), '/domains');
        }

        return $this->renderShow($row['domain'], $live);
    }

    public function updateSettings(array $params): Response
    {
        $row = $this->ownedDomain($params['domain']);
        if ($row === null) {
            return $this->notFound();
        }

        try {
            $current = $this->app->api->getDomain($row['domain']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], 'error', $e->getMessage());
        }

        $nameservers = $this->parseNameservers((string) ($_POST['nameservers'] ?? ''));
        if (is_string($nameservers)) {
            return $this->backWithFlash($row['domain'], 'error', $nameservers);
        }

        $autoRenew = isset($_POST['auto_renew']);
        $transferLock = isset($_POST['transfer_lock']);
        $contactHandle = trim((string) ($_POST['contact_handle'] ?? ''));

        $changes = [];
        if ($nameservers !== $current['nameservers']) {
            $changes['nameservers'] = $nameservers;
        }
        if ($autoRenew !== $current['auto_renew']) {
            $changes['auto_renew'] = $autoRenew;
        }
        if ($transferLock !== $current['transfer_lock']) {
            $changes['transfer_lock'] = $transferLock;
        }
        if ($contactHandle !== '' && $contactHandle !== $current['contact_handle']) {
            if (!$this->userOwnsContact($contactHandle)) {
                return $this->backWithFlash($row['domain'], 'error', 'Please choose a valid contact.');
            }
            $changes['whois_contact_handle'] = $contactHandle;
        }

        if ($changes === []) {
            return $this->backWithFlash($row['domain'], 'info', 'Nothing to update.');
        }

        try {
            $this->app->api->updateDomain($row['domain'], $changes);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], 'error', $e->getMessage());
        }

        return $this->backWithFlash($row['domain'], 'success', 'Domain settings saved.');
    }

    public function revealAuthCode(array $params): Response
    {
        $row = $this->ownedDomain($params['domain']);
        if ($row === null) {
            return $this->notFound();
        }

        try {
            $live = $this->app->api->getDomain($row['domain']);
            $authCode = $this->app->api->getAuthCode($row['domain']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], 'error', $e->getMessage());
        }

        return $this->renderShow($row['domain'], $live, $authCode);
    }

    /** @return array{domain: string, status: string, expires_at: ?string, unavailable: bool} */
    private function withLiveDetails(array $row): array
    {
        try {
            $live = $this->app->api->getDomain($row['domain']);

            return [
                'domain' => $row['domain'],
                'status' => $live['status'],
                'expires_at' => $live['expires_at'],
                'unavailable' => false,
            ];
        } catch (ApiException) {
            return [
                'domain' => $row['domain'],
                'status' => $row['status'],
                'expires_at' => null,
                'unavailable' => true,
            ];
        }
    }

    private function renderShow(string $domain, array $live, ?string $authCode = null): Response
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM contacts WHERE user_id = ? ORDER BY name');
        $stmt->execute([$this->user()['id']]);

        return Response::html($this->app->view->render('domains/show', [
            'title' => $domain,
            'domain' => $domain,
            'live' => $live,
            'contacts' => $stmt->fetchAll(),
            'auth_code' => $authCode,
        ]));
    }

    /** @return list<string>|string list of lowercase hostnames on success, error message on failure */
    private function parseNameservers(string $raw): array|string
    {
        $lines = preg_split('/\r\n|\r|\n/', trim($raw));
        $nameservers = array_values(array_filter(
            array_map(static fn (string $line) => strtolower(trim($line)), $lines),
            static fn (string $line) => $line !== ''
        ));

        if (count($nameservers) < 2 || count($nameservers) > 4) {
            return 'Please provide between 2 and 4 nameservers.';
        }

        foreach ($nameservers as $ns) {
            if (!preg_match(self::NAMESERVER_PATTERN, $ns)) {
                return "'{$ns}' is not a valid nameserver hostname.";
            }
        }

        return $nameservers;
    }

    private function userOwnsContact(string $handle): bool
    {
        $stmt = $this->app->pdo->prepare('SELECT 1 FROM contacts WHERE user_id = ? AND handle = ?');
        $stmt->execute([$this->user()['id'], $handle]);

        return $stmt->fetch() !== false;
    }

    private function ownedDomain(string $domain): ?array
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM domains WHERE domain = ? AND user_id = ?');
        $stmt->execute([$domain, $this->user()['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function backWithFlash(string $domain, string $type, string $message, ?string $to = null): Response
    {
        $_SESSION['flash'] = ['type' => $type, 'message' => $message];

        return Response::redirect($to ?? '/domains/' . rawurlencode($domain));
    }

    private function notFound(): Response
    {
        return Response::html($this->app->view->render('404', ['title' => 'Page not found']), 404);
    }

    private function user(): array
    {
        return $this->app->auth->user();
    }
}
