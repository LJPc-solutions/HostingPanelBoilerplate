<?php

declare(strict_types=1);

namespace Panel;

final class View
{
    public function __construct(
        private readonly string $templateDir,
        private array $shared = [],
    ) {
    }

    public function share(string $key, mixed $value): void
    {
        $this->shared[$key] = $value;
    }

    public function render(string $template, array $data = []): string
    {
        $content = $this->renderBare($template, $data);

        return $this->renderBare('layout', array_merge($data, ['content' => $content]));
    }

    public function renderBare(string $template, array $data = []): string
    {
        $file = $this->templateDir . '/' . $template . '.php';
        $render = function () use ($file, $data): string {
            extract($this->shared, EXTR_SKIP);
            extract($data, EXTR_OVERWRITE);
            ob_start();
            require $file;

            return (string) ob_get_clean();
        };

        return $render();
    }
}
