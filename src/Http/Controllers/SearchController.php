<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Http\Validation\Validator;
use Twig\Environment;

class SearchController extends BaseController
{
    public function __construct(
        Environment $twig,
        private CollectionRepositoryInterface $collectionRepository,
        Validator $validator
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string, mixed>|null $currentUser */
    public function save(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->redirect('/?error=csrf');
            }
            if (!$this->validator->validate($_POST, ['name' => 'required', 'q' => 'required'])) {
                $this->redirect('/?error=invalid_data');
            }
            $name = trim((string)($_POST['name'] ?? ''));
            $query = trim((string)($_POST['q'] ?? ''));
            $this->collectionRepository->saveSearch((int)$currentUser['id'], $name, $query);
            $this->redirect('/?q=' . urlencode($query));
        }
    }

    /** @param array<string, mixed>|null $currentUser */
    public function delete(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->redirect('/?error=csrf');
            }
            $id = (int)($_POST['id'] ?? 0);
            $this->collectionRepository->deleteSearch($id, (int)$currentUser['id']);
            $this->redirect('/');
        }
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
