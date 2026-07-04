<?php
declare(strict_types=1);

namespace App\Http\Controllers;

class HelpController extends BaseController
{
    public function index(): void
    {
        $this->render('help.html.twig', ['title' => 'Help']);
    }
}
