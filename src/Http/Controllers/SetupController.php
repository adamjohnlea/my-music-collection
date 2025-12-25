<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Config;

class SetupController extends BaseController
{
    public function index(): void
    {
        $envFile = dirname(__DIR__, 2) . '/.env';
        
        // If .env exists and has a token, we don't need setup
        if (file_exists($envFile)) {
            $config = new Config();
            if ($config->getDiscogsToken() && $config->getDiscogsUsername()) {
                $this->redirect('/');
            }
        }

        $this->render('setup.html.twig');
    }

    public function save(): void
    {
        $token = $_POST['token'] ?? '';
        $username = $_POST['username'] ?? '';

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
        $envContent .= "DISCOGS_USERNAME=$username\n";
        $envContent .= "DISCOGS_TOKEN=$token\n";

        $envFile = dirname(__DIR__, 2) . '/.env';
        
        if (file_put_contents($envFile, $envContent) === false) {
            $this->render('setup.html.twig', ['error' => 'Failed to write .env file. Please check directory permissions.']);
            return;
        }
        
        // Clear any cached environment variables if possible, though a redirect usually suffices
        $this->redirect('/');
    }
}
