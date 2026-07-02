<?php

declare(strict_types=1);

namespace Panel\Controllers;

use Panel\Api\ApiException;
use Panel\App;
use Panel\Dns;
use Panel\Response;

final class DnsController
{
    public function __construct(private readonly App $app)
    {
    }

    public function edit(array $params): Response
    {
        $row = $this->ownedDomain($params['domain']);
        if ($row === null) {
            return $this->notFound();
        }

        try {
            $records = $this->app->api->getDnsRecords($row['domain']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], $e->getMessage());
        }

        return $this->render($row['domain'], $this->toDisplayRows($records), []);
    }

    public function save(array $params): Response
    {
        $row = $this->ownedDomain($params['domain']);
        if ($row === null) {
            return $this->notFound();
        }

        $rows = $this->parsePostedRows();
        $result = Dns::validate($rows);

        if (!$result['ok']) {
            return $this->render($row['domain'], $rows, $result['errors']);
        }

        try {
            $this->app->api->saveDnsRecords($row['domain'], $result['records']);
        } catch (ApiException $e) {
            return $this->backWithFlash($row['domain'], $e->getMessage());
        }

        $_SESSION['flash'] = ['type' => 'success', 'message' => 'DNS records saved.'];

        return Response::redirect('/domains/' . rawurlencode($row['domain']) . '/dns');
    }

    /** @param list<array{type: string, name: string, content: string, ttl: int, prio: ?int}> $apiRecords
     *  @return list<array{type: string, name: string, content: string, ttl: string, prio: string}> */
    private function toDisplayRows(array $apiRecords): array
    {
        return array_map(static fn (array $r): array => [
            'type' => $r['type'],
            'name' => $r['name'],
            'content' => $r['content'],
            'ttl' => (string) $r['ttl'],
            'prio' => $r['prio'] !== null ? (string) $r['prio'] : '',
        ], $apiRecords);
    }

    /** @return list<array{type: string, name: string, content: string, ttl: string, prio: string}> rows with checked "delete" rows dropped */
    private function parsePostedRows(): array
    {
        $posted = is_array($_POST['records'] ?? null) ? $_POST['records'] : [];

        $rows = [];
        foreach ($posted as $row) {
            if (!is_array($row) || isset($row['delete'])) {
                continue;
            }
            $rows[] = [
                'type' => (string) ($row['type'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'content' => (string) ($row['content'] ?? ''),
                'ttl' => (string) ($row['ttl'] ?? ''),
                'prio' => (string) ($row['prio'] ?? ''),
            ];
        }

        return $rows;
    }

    private function render(string $domain, array $records, array $errors): Response
    {
        return Response::html($this->app->view->render('domains/dns', [
            'title' => 'DNS records for ' . $domain,
            'domain' => $domain,
            'records' => $records,
            'errors' => $errors,
        ]));
    }

    private function ownedDomain(string $domain): ?array
    {
        $stmt = $this->app->pdo->prepare('SELECT * FROM domains WHERE domain = ? AND user_id = ?');
        $stmt->execute([$domain, $this->user()['id']]);
        $row = $stmt->fetch();

        return $row === false ? null : $row;
    }

    private function backWithFlash(string $domain, string $message): Response
    {
        $_SESSION['flash'] = ['type' => 'error', 'message' => $message];

        return Response::redirect('/domains/' . rawurlencode($domain));
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
