<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use Twig\Environment;

abstract class BaseController
{
    public function __construct(
        protected Environment $twig
    ) {}

    protected function render(string $template, array $data = []): void
    {
        echo $this->twig->render($template, $data);
    }

    protected function redirect(string $url): void
    {
        header('Location: ' . $url);
        exit;
    }
}
