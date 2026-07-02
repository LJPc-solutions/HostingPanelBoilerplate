<?php

declare(strict_types=1);

namespace Panel\Api;

final class MockStore
{
    public function __construct(private readonly string $dir)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $file = $this->file($key);
        if (!is_file($file)) {
            return $default;
        }

        return json_decode((string) file_get_contents($file), true);
    }

    public function set(string $key, mixed $value): void
    {
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0770, true);
        }
        file_put_contents($this->file($key), json_encode($value), LOCK_EX);
    }

    private function file(string $key): string
    {
        return $this->dir . '/' . md5($key) . '.json';
    }
}
