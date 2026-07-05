<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Repositories\CollectionRepositoryInterface;
use App\Domain\Valuation\CurrencyFormat;
use App\Domain\RelativeTime;
use App\Http\Validation\Validator;
use Twig\Environment;

// NOT final: AlertsControllerTest subclasses this to override redirect() (mirrors SearchController).
class AlertsController extends BaseController
{
    public function __construct(
        Environment $twig,
        private CollectionRepositoryInterface $repo,
        Validator $validator,
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string, mixed>|null $currentUser */
    public function index(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];

        $now = time();
        $rows = $this->repo->listWantlistAlerts($username);
        $alerts = array_map(static function (array $a) use ($now): array {
            $symbol = CurrencyFormat::symbol($a['currency']);
            $a['new_price_display'] = $symbol . number_format($a['new_price'], 2);
            $a['old_price_display'] = $a['old_price'] !== null ? $symbol . number_format($a['old_price'], 2) : null;
            $a['when'] = RelativeTime::ago($a['created_at'], $now);
            $a['is_unread'] = $a['read_at'] === null;
            return $a;
        }, $rows);

        $this->render('alerts.html.twig', ['title' => 'Price Alerts', 'alerts' => $alerts]);

        // Mark read AFTER building the view so this render still shows unread styling.
        $this->repo->markWantlistAlertsRead($username, gmdate('c'));
    }

    /** @param array<string, mixed>|null $currentUser */
    public function dismiss(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (!$this->isCsrfValid()) { $this->redirect('/alerts'); }
        $id = (int)($_POST['id'] ?? 0);
        if ($id > 0) {
            $this->repo->dismissWantlistAlert($id, (string)$currentUser['discogs_username'], gmdate('c'));
        }
        $this->redirect('/alerts');
    }

    /** @param array<string, mixed>|null $currentUser */
    public function setTarget(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        if (!$this->isCsrfValid()) { $this->redirect('/?view=wantlist'); }

        $username = (string)$currentUser['discogs_username'];
        $rid = (int)($_POST['release_id'] ?? 0);
        $raw = trim((string)($_POST['target'] ?? ''));
        $target = $raw === '' ? null : (float)$raw;
        if ($target !== null && $target <= 0) { $target = null; } // non-positive clears

        if ($rid > 0) {
            $this->repo->setWantlistTarget($rid, $username, $target);
        }

        $ret = (string)($_POST['return'] ?? '/?view=wantlist');
        if (!str_starts_with($ret, '/')) { $ret = '/?view=wantlist'; }
        $this->redirect($ret);
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
