<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Achievements\AchievementService;
use App\Http\Validation\Validator;
use Twig\Environment;

// NOT final: AchievementsControllerTest subclasses this to capture render()/redirect().
class AchievementsController extends BaseController
{
    public function __construct(
        Environment $twig,
        private AchievementService $service,
        Validator $validator,
    ) {
        parent::__construct($twig, $validator);
    }

    /** @param array<string,mixed>|null $currentUser */
    public function index(?array $currentUser): void
    {
        if (!$currentUser) { $this->redirect('/'); }
        $username = (string)$currentUser['discogs_username'];

        // Grid already carries per-badge is_new flags captured before we mark seen.
        $grid = $this->service->evaluateAndPersist($username);

        $this->render('achievements.html.twig', [
            'title' => 'Achievements',
            'grid' => $grid,
        ]);

        // Acknowledge AFTER building the view so this render still shows "new" styling.
        $this->service->markSeen($username);
    }
}
