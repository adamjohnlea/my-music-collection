<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Theme\ThemeRegistry;
use App\Domain\Theme\ThemeService;
use App\Http\Validation\Validator;
use Twig\Environment;

class ThemeController extends BaseController
{
    public function __construct(
        Environment $twig,
        Validator $validator,
        private readonly ThemeService $themes
    ) {
        parent::__construct($twig, $validator);
    }

    public function index(): void
    {
        $error = $_GET['error'] ?? null;
        $this->render('theme.html.twig', [
            'title' => 'Theme - Appearance',
            'groups' => ThemeRegistry::groups(),
            'presets' => ThemeRegistry::presets(),
            'current' => $this->themes->current(),
            'saved' => isset($_GET['saved']),
            'reset' => isset($_GET['reset']),
            'error' => in_array($error, ['csrf', 'invalid'], true) ? $error : null,
        ]);
    }

    public function save(): void
    {
        if (!$this->isCsrfValid()) {
            $this->redirect('/theme?error=csrf');
            return;
        }
        $mode = is_string($_POST['mode'] ?? null) ? $_POST['mode'] : 'dark';
        /** @var array<string,string> $overrides */
        $overrides = [];
        if (is_array($_POST['overrides'] ?? null)) {
            foreach ($_POST['overrides'] as $k => $v) {
                if (is_string($k) && is_string($v) && trim($v) !== '') {
                    $overrides[$k] = trim($v);
                }
            }
        }
        try {
            $this->themes->save($mode, $overrides);
        } catch (\InvalidArgumentException) {
            $this->redirect('/theme?error=invalid');
            return;
        }
        $this->redirect('/theme?saved=1');
    }

    public function reset(): void
    {
        if (!$this->isCsrfValid()) {
            $this->redirect('/theme?error=csrf');
            return;
        }
        $this->themes->reset();
        $this->redirect('/theme?reset=1');
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf'])
            && is_string($_POST['_token'])
            && hash_equals((string)$_SESSION['csrf'], $_POST['_token']);
    }
}
