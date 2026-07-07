<?php

declare(strict_types=1);

namespace App\Core;

abstract class Controller
{
    protected Database $db;
    protected View $view;
    protected Auth $auth;

    public function __construct(protected readonly array $config)
    {
        $this->db = Database::instance($config['db']);
        $this->view = new View($config);
        $this->auth = new Auth($this->db);
    }

    protected function redirect(string $path): never
    {
        header('Location: ' . app_url($path));
        exit;
    }
}
