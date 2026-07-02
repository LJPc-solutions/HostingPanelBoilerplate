<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function panel_config(string $rootDir): array
{
    $file = is_file($rootDir . '/config.php')
        ? $rootDir . '/config.php'
        : $rootDir . '/config.example.php';

    return require $file;
}

function valid_domain_name(string $domain): bool
{
    if (!str_contains($domain, '.') || strlen($domain) > 253) {
        return false;
    }
    [$sld] = explode('.', $domain, 2);

    return valid_sld($sld) && (bool) preg_match('/^([a-z0-9-]+\.)+[a-z]{2,}$/', $domain);
}

function valid_sld(string $sld): bool
{
    return (bool) preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/', $sld);
}

function format_bytes(int $bytes): string
{
    if ($bytes <= 0) {
        return '0 B';
    }
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = min((int) floor(log($bytes, 1024)), count($units) - 1);
    $value = $bytes / (1024 ** $i);

    return ($i === 0 ? (string) $bytes : number_format($value, 1)) . ' ' . $units[$i];
}
