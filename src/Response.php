<?php

declare(strict_types=1);

namespace Panel;

final class Response
{
    public function __construct(
        public readonly int $status = 200,
        public readonly array $headers = [],
        public readonly string $body = '',
    ) {
    }

    public static function html(string $body, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'text/html; charset=utf-8'], $body);
    }

    public static function redirect(string $to, int $status = 302): self
    {
        return new self($status, ['Location' => $to]);
    }

    public static function json(array $data, int $status = 200): self
    {
        return new self($status, ['Content-Type' => 'application/json'], json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function send(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        echo $this->body;
    }
}
