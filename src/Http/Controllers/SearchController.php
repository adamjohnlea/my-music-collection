<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use PDO;
use Twig\Environment;

class SearchController extends BaseController
{
    public function __construct(
        Environment $twig,
        private PDO $pdo
    ) {
        parent::__construct($twig);
    }

    public function save(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/login'); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->redirect('/?error=csrf');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            $query = trim((string)($_POST['q'] ?? ''));
            if ($name !== '' && $query !== '') {
                $st = $this->pdo->prepare('INSERT INTO saved_searches (user_id, name, query) VALUES (:uid, :name, :query)');
                $st->execute([':uid' => $currentUser['id'], ':name' => $name, ':query' => $query]);
            }
            $this->redirect('/?q=' . urlencode($query));
        }
    }

    public function delete(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/login'); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->redirect('/?error=csrf');
            }
            $id = (int)($_POST['id'] ?? 0);
            $st = $this->pdo->prepare('DELETE FROM saved_searches WHERE id = :id AND user_id = :uid');
            $st->execute([':id' => $id, ':uid' => $currentUser['id']]);
            $this->redirect('/');
        }
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
