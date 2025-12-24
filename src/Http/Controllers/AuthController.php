<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Crypto;
use App\Infrastructure\KvStore;
use PDO;
use Twig\Environment;

class AuthController extends BaseController
{
    public function __construct(
        Environment $twig,
        private PDO $pdo,
        private Crypto $crypto
    ) {
        parent::__construct($twig);
    }

    public function login(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->render('auth/login.html.twig', [
                    'title' => 'Sign in',
                    'error' => 'Invalid request. Please try again.',
                    'old' => ['username' => (string)($_POST['username'] ?? '')]
                ]);
                return;
            }
            $usernameOrEmail = trim((string)($_POST['username'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $st = $this->pdo->prepare('SELECT id, password_hash FROM auth_users WHERE username = :u OR email = :u');
            $st->execute([':u' => $usernameOrEmail]);
            $row = $st->fetch();
            if ($row && password_verify($password, (string)$row['password_hash'])) {
                session_regenerate_id(true);
                $_SESSION['uid'] = (int)$row['id'];
                try {
                    $kv = new KvStore($this->pdo);
                    $kv->set('current_user_id', (string)$_SESSION['uid']);
                } catch (\Throwable $e) { /* non-fatal */ }
                $dest = '/';
                if (isset($_GET['return']) && is_string($_GET['return']) && str_starts_with($_GET['return'], '/')) {
                    $dest = $_GET['return'];
                }
                $this->redirect($dest);
            } else {
                $error = 'Invalid credentials';
            }
            $this->render('auth/login.html.twig', [
                'title' => 'Sign in',
                'error' => $error ?? null,
                'old' => ['username' => $usernameOrEmail]
            ]);
            return;
        }
        $this->render('auth/login.html.twig', ['title' => 'Sign in']);
    }

    public function register(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $this->render('auth/register.html.twig', [
                    'title' => 'Create account',
                    'errors' => ['Invalid request. Please try again.'],
                    'old' => ['username' => (string)($_POST['username'] ?? ''), 'email' => (string)($_POST['email'] ?? '')],
                ]);
                return;
            }
            $username = trim((string)($_POST['username'] ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $password = (string)($_POST['password'] ?? '');
            $confirm = (string)($_POST['confirm'] ?? '');
            $errors = [];
            if ($username === '' || !preg_match('/^[A-Za-z0-9_\-\.]{3,32}$/', $username)) $errors[] = 'Invalid username';
            if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email';
            if (strlen($password) < 8) $errors[] = 'Password must be at least 8 characters';
            if ($password !== $confirm) $errors[] = 'Passwords do not match';
            if (!$errors) {
                $exists = $this->pdo->prepare('SELECT 1 FROM auth_users WHERE username = :u OR email = :e');
                $exists->execute([':u' => $username, ':e' => $email]);
                if ($exists->fetchColumn()) {
                    $errors[] = 'Username or email already in use';
                } else {
                    $hash = password_hash($password, PASSWORD_DEFAULT);
                    $ins = $this->pdo->prepare('INSERT INTO auth_users (username, email, password_hash) VALUES (:u, :e, :p)');
                    $ins->execute([':u' => $username, ':e' => $email, ':p' => $hash]);
                    $_SESSION['uid'] = (int)$this->pdo->lastInsertId();
                    $this->redirect('/settings');
                }
            }
            $this->render('auth/register.html.twig', [
                'title' => 'Create account',
                'errors' => $errors,
                'old' => ['username' => $username, 'email' => $email],
            ]);
            return;
        }
        $this->render('auth/register.html.twig', ['title' => 'Create account']);
    }

    public function logout(): void
    {
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if ($this->isCsrfValid()) {
                try {
                    $kv = new KvStore($this->pdo);
                    $kv->set('current_user_id', '');
                } catch (\Throwable $e) { /* non-fatal */ }
                session_destroy();
            }
        }
        $this->redirect('/');
    }

    public function settings(?array $currentUser): void
    {
        if (!$currentUser) {
            $this->redirect('/login');
        }

        $discogsUsername = $currentUser['discogs_username'] ?? '';
        $discogsToken = $currentUser['discogs_token'] ?? '';
        $excludeTitle = (bool)($currentUser['discogs_search_exclude_title'] ?? false);
        $saved = false;
        $error = null;

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
            if (!$this->isCsrfValid()) {
                $error = 'Invalid request. Please try again.';
            } else {
                $discogsUsername = trim((string)($_POST['discogs_username'] ?? ''));
                $discogsToken = trim((string)($_POST['discogs_token'] ?? ''));
                $excludeTitle = isset($_POST['discogs_search_exclude_title']) && $_POST['discogs_search_exclude_title'] === '1';
                if ($discogsUsername === '' || $discogsToken === '') {
                    $error = 'Both Discogs username and token are required';
                } else {
                    $enc = $this->crypto->encrypt($discogsToken);
                    $up = $this->pdo->prepare('UPDATE auth_users SET discogs_username = :u, discogs_token_enc = :t, discogs_search_exclude_title = :et, updated_at = CURRENT_TIMESTAMP WHERE id = :id');
                    $up->execute([':u' => $discogsUsername, ':t' => $enc, ':et' => (int)$excludeTitle, ':id' => (int)$currentUser['id']]);
                    $saved = true;
                }
            }
        }

        $this->render('auth/settings.html.twig', [
            'title' => 'Settings',
            'discogs_username' => $discogsUsername,
            'discogs_token' => $discogsToken,
            'discogs_search_exclude_title' => $excludeTitle,
            'saved' => $saved,
            'error' => $error,
        ]);
    }

    private function isCsrfValid(): bool
    {
        return isset($_POST['_token'], $_SESSION['csrf']) && hash_equals($_SESSION['csrf'], (string)$_POST['_token']);
    }
}
