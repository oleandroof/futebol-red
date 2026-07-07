<?php

declare(strict_types=1);

namespace App\Core;

final class View
{
    public function __construct(private readonly array $config)
    {
    }

    public function render(string $template, array $data = []): void
    {
        extract($data, EXTR_SKIP);
        $config = $this->config;
        require __DIR__ . '/../Views/' . $template . '.php';
    }
}
