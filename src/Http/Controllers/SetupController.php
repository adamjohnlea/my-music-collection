<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Config;

class SetupController extends BaseController
{
    public function index(): void
    {
        $config = new Config();
        
        // If .env exists and has valid credentials, we don't need setup
        if ($config->hasValidCredentials()) {
            $this->redirect('/');
        }

        $this->render('setup.html.twig');
    }

    public function save(): void
    {
        $token = $_POST['token'] ?? '';
        $username = $_POST['username'] ?? '';
        $csrfToken = $_POST['_token'] ?? '';

        if (empty($csrfToken) || !hash_equals($_SESSION['csrf'] ?? '', $csrfToken)) {
            error_log(sprintf(
                'CSRF mismatch in SetupController. POST: %s, SESSION: %s',
                $csrfToken,
                $_SESSION['csrf'] ?? 'NOT_SET'
            ));
            $this->render('setup.html.twig', ['error' => 'Invalid CSRF token. Please try refreshing the page.']);
            return;
        }

        if (empty($token) || empty($username)) {
            $this->render('setup.html.twig', ['error' => 'All fields are required.']);
            return;
        }

        // Prepare the .env content
        $envContent = "USER_AGENT=\"MyDiscogsApp/1.0 (+https://github.com/your-username/my-music-collection)\"\n";
        $envContent .= "DB_PATH=var/app.db\n";
        $envContent .= "IMG_DIR=public/images\n";
        $envContent .= "APP_ENV=prod\n";
        $envContent .= "APP_DEBUG=0\n";
        $envContent .= "PUSH_NOTES=1\n";
        $envContent .= "DISCOGS_USERNAME=$username\n";
        $envContent .= "DISCOGS_TOKEN=$token\n";

        $envFile = dirname(__DIR__, 3) . '/.env';
        
        // Debug info if writing fails
        if (file_put_contents($envFile, $envContent) === false) {
            $error = 'Failed to write .env file.';
            if (!is_writable(dirname($envFile))) {
                $error .= ' The directory is not writable.';
            } elseif (file_exists($envFile) && !is_writable($envFile)) {
                $error .= ' The .env file exists but is not writable.';
            }
            $this->render('setup.html.twig', ['error' => $error . ' Please check directory permissions.']);
            return;
        }
        
        // Clear any cached environment variables if possible, though a redirect usually suffices
        $this->redirect('/');
    }
}
