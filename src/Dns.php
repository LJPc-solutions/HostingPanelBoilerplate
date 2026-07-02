<?php

declare(strict_types=1);

namespace Panel;

final class Dns
{
    private const TYPES = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'SRV', 'CAA'];

    private const NAME_PATTERN = '/^(@|[a-z0-9_]([a-z0-9_.-]*[a-z0-9_])?)$/i';

    private const HOSTNAME_PATTERN = '/^(?=.{4,253}$)([a-z0-9-]+\.)+[a-z]{2,}$/i';

    /**
     * @param list<array{type: string, name: string, content: string, ttl: string, prio: string}> $rows
     * @return array{ok: bool, errors: list<string>, records: list<array{type: string, name: string, content: string, ttl: int, prio: ?int}>}
     */
    public static function validate(array $rows): array
    {
        $errors = [];
        $records = [];

        foreach (array_values($rows) as $index => $row) {
            $rowNumber = $index + 1;
            $rowErrors = [];

            $type = strtoupper(trim((string) ($row['type'] ?? '')));
            if (!in_array($type, self::TYPES, true)) {
                $rowErrors[] = 'invalid record type.';
            }

            $name = trim((string) ($row['name'] ?? ''));
            if (!preg_match(self::NAME_PATTERN, $name)) {
                $rowErrors[] = 'invalid name.';
            }

            $ttlRaw = (string) ($row['ttl'] ?? '');
            $ttl = null;
            if (!ctype_digit($ttlRaw) || (int) $ttlRaw < 60 || (int) $ttlRaw > 86400) {
                $rowErrors[] = 'TTL must be between 60 and 86400.';
            } else {
                $ttl = (int) $ttlRaw;
            }

            $content = trim((string) ($row['content'] ?? ''));
            if (in_array($type, self::TYPES, true)) {
                $contentError = self::validateContent($type, $content);
                if ($contentError !== null) {
                    $rowErrors[] = $contentError;
                } else {
                    $content = self::normalizeContent($type, $content);
                }
            }

            $prioRaw = trim((string) ($row['prio'] ?? ''));
            $prio = null;
            if (in_array($type, ['MX', 'SRV'], true)) {
                if (!ctype_digit($prioRaw) || (int) $prioRaw < 0 || (int) $prioRaw > 65535) {
                    $rowErrors[] = 'priority is required and must be between 0 and 65535.';
                } else {
                    $prio = (int) $prioRaw;
                }
            }

            if ($rowErrors !== []) {
                foreach ($rowErrors as $rowError) {
                    $errors[] = "Row {$rowNumber}: {$rowError}";
                }

                continue;
            }

            $records[] = [
                'type' => $type,
                'name' => $name,
                'content' => $content,
                'ttl' => $ttl,
                'prio' => $prio,
            ];
        }

        return [
            'ok' => $errors === [],
            'errors' => $errors,
            'records' => $records,
        ];
    }

    private static function validateContent(string $type, string $content): ?string
    {
        return match ($type) {
            'A' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
                ? null : 'invalid IPv4 address.',
            'AAAA' => filter_var($content, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false
                ? null : 'invalid IPv6 address.',
            'CNAME', 'MX', 'SRV' => preg_match(self::HOSTNAME_PATTERN, rtrim($content, '.')) === 1
                ? null : 'invalid hostname.',
            'TXT', 'CAA' => ($content !== '' && strlen($content) <= 4096)
                ? null : 'content must be non-empty and at most 4096 characters.',
            default => null,
        };
    }

    private static function normalizeContent(string $type, string $content): string
    {
        return match ($type) {
            'CNAME', 'MX', 'SRV' => rtrim($content, '.'),
            default => $content,
        };
    }
}
