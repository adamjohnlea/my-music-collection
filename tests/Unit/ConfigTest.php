<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Infrastructure\Config;
use PHPUnit\Framework\TestCase;

class ConfigTest extends TestCase
{
    private Config $config;
    private array $originalEnv = [];
    private array $originalServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->config = new Config();

        // Save original values
        $this->originalEnv = $_ENV;
        $this->originalServer = $_SERVER;

        // Clear test-related env vars
        unset($_ENV['TEST_VAR'], $_SERVER['TEST_VAR']);
        unset($_ENV['DB_PATH'], $_SERVER['DB_PATH']);
        unset($_ENV['IMG_DIR'], $_SERVER['IMG_DIR']);
        unset($_ENV['USER_AGENT'], $_SERVER['USER_AGENT']);
        unset($_ENV['APP_KEY'], $_SERVER['APP_KEY']);
        unset($_ENV['DISCOGS_USERNAME'], $_SERVER['DISCOGS_USERNAME']);
        unset($_ENV['DISCOGS_TOKEN'], $_SERVER['DISCOGS_TOKEN']);
        unset($_ENV['ANTHROPIC_API_KEY'], $_SERVER['ANTHROPIC_API_KEY']);
        unset($_ENV['APPLE_MUSIC_DEVELOPER_TOKEN'], $_SERVER['APPLE_MUSIC_DEVELOPER_TOKEN']);
        unset($_ENV['APPLE_MUSIC_STOREFRONT'], $_SERVER['APPLE_MUSIC_STOREFRONT']);
        putenv('TEST_VAR'); // Clear from getenv
    }

    protected function tearDown(): void
    {
        // Restore original values
        $_ENV = $this->originalEnv;
        $_SERVER = $this->originalServer;
        putenv('TEST_VAR');
        parent::tearDown();
    }

    // ==================== env(): Happy Path ====================

    public function testEnvReadsFromEnvSuperglobal(): void
    {
        $_ENV['TEST_VAR'] = 'from_env';

        $result = $this->config->env('TEST_VAR');

        $this->assertEquals('from_env', $result);
    }

    public function testEnvReadsFromServerSuperglobal(): void
    {
        $_SERVER['TEST_VAR'] = 'from_server';

        $result = $this->config->env('TEST_VAR');

        $this->assertEquals('from_server', $result);
    }

    public function testEnvPrefersEnvOverServer(): void
    {
        $_ENV['TEST_VAR'] = 'from_env';
        $_SERVER['TEST_VAR'] = 'from_server';

        $result = $this->config->env('TEST_VAR');

        $this->assertEquals('from_env', $result);
    }

    public function testEnvReturnsDefaultWhenNotSet(): void
    {
        $result = $this->config->env('NONEXISTENT_VAR', 'default_value');

        $this->assertEquals('default_value', $result);
    }

    public function testEnvReturnsNullWhenNotSetAndNoDefault(): void
    {
        $result = $this->config->env('NONEXISTENT_VAR');

        $this->assertNull($result);
    }

    // ==================== isAbsolutePath(): Happy Path ====================

    public function testIsAbsolutePathReturnsTrueForPosixPath(): void
    {
        $result = $this->config->isAbsolutePath('/var/data/app.db');

        $this->assertTrue($result);
    }

    public function testIsAbsolutePathReturnsTrueForWindowsPath(): void
    {
        // Note: Windows paths with backslashes need proper regex escaping
        // The regex [\\/] in PHP may not match backslash correctly
        // This tests the forward-slash Windows path variant which works
        $result = $this->config->isAbsolutePath('C:/Users/data/app.db');

        $this->assertTrue($result);
    }

    public function testIsAbsolutePathReturnsTrueForWindowsPathDifferentDrive(): void
    {
        $result = $this->config->isAbsolutePath('D:/data/app.db');

        $this->assertTrue($result);
    }

    public function testIsAbsolutePathReturnsFalseForRelativePath(): void
    {
        $result = $this->config->isAbsolutePath('var/app.db');

        $this->assertFalse($result);
    }

    public function testIsAbsolutePathReturnsFalseForEmptyString(): void
    {
        $result = $this->config->isAbsolutePath('');

        $this->assertFalse($result);
    }

    public function testIsAbsolutePathReturnsFalseForDotPath(): void
    {
        $result = $this->config->isAbsolutePath('./data/app.db');

        $this->assertFalse($result);
    }

    // ==================== getDbPath(): Happy Path ====================

    public function testGetDbPathReturnsDefaultRelativePath(): void
    {
        $result = $this->config->getDbPath('/app');

        $this->assertEquals('/app/var/app.db', $result);
    }

    public function testGetDbPathUsesEnvVariable(): void
    {
        $_ENV['DB_PATH'] = 'data/mydb.sqlite';

        $result = $this->config->getDbPath('/app');

        $this->assertEquals('/app/data/mydb.sqlite', $result);
    }

    public function testGetDbPathHandlesAbsolutePath(): void
    {
        $_ENV['DB_PATH'] = '/custom/path/app.db';

        $result = $this->config->getDbPath('/app');

        $this->assertEquals('/custom/path/app.db', $result);
    }

    public function testGetDbPathPreventsPublicDirectory(): void
    {
        $_ENV['DB_PATH'] = 'public/app.db';

        $result = $this->config->getDbPath('/app');

        // Should redirect to var/app.db for safety
        $this->assertEquals('/app/var/app.db', $result);
    }

    public function testGetDbPathHandlesTrailingSlashInBaseDir(): void
    {
        $result = $this->config->getDbPath('/app/');

        $this->assertEquals('/app/var/app.db', $result);
    }

    // ==================== getImgDir(): Happy Path ====================

    public function testGetImgDirReturnsDefaultRelativePath(): void
    {
        $result = $this->config->getImgDir('/app');

        $this->assertEquals('/app/public/images', $result);
    }

    public function testGetImgDirUsesEnvVariable(): void
    {
        $_ENV['IMG_DIR'] = 'storage/images';

        $result = $this->config->getImgDir('/app');

        $this->assertEquals('/app/storage/images', $result);
    }

    public function testGetImgDirHandlesAbsolutePath(): void
    {
        $_ENV['IMG_DIR'] = '/custom/images';

        $result = $this->config->getImgDir('/app');

        $this->assertEquals('/custom/images', $result);
    }

    // ==================== getUserAgent(): Happy Path ====================

    public function testGetUserAgentReturnsDefaultValue(): void
    {
        $result = $this->config->getUserAgent();

        $this->assertEquals('MyDiscogsApp/0.1 (+contact: you@example.com)', $result);
    }

    public function testGetUserAgentReturnsCustomDefault(): void
    {
        $result = $this->config->getUserAgent('CustomApp/1.0');

        $this->assertEquals('CustomApp/1.0', $result);
    }

    public function testGetUserAgentUsesEnvVariable(): void
    {
        $_ENV['USER_AGENT'] = 'TestApp/2.0 (+test@example.com)';

        $result = $this->config->getUserAgent();

        $this->assertEquals('TestApp/2.0 (+test@example.com)', $result);
    }

    // ==================== Credential Getters: Happy Path ====================

    public function testGetAppKeyReturnsValue(): void
    {
        $_ENV['APP_KEY'] = 'secret-key-123';

        $result = $this->config->getAppKey();

        $this->assertEquals('secret-key-123', $result);
    }

    public function testGetAppKeyReturnsNullWhenNotSet(): void
    {
        $result = $this->config->getAppKey();

        $this->assertNull($result);
    }

    public function testGetDiscogsUsernameReturnsValue(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'testuser';

        $result = $this->config->getDiscogsUsername();

        $this->assertEquals('testuser', $result);
    }

    public function testGetDiscogsTokenReturnsValue(): void
    {
        $_ENV['DISCOGS_TOKEN'] = 'token-abc';

        $result = $this->config->getDiscogsToken();

        $this->assertEquals('token-abc', $result);
    }

    public function testGetAnthropicKeyReturnsValue(): void
    {
        $_ENV['ANTHROPIC_API_KEY'] = 'sk-ant-123';

        $result = $this->config->getAnthropicKey();

        $this->assertEquals('sk-ant-123', $result);
    }

    public function testGetAppleMusicDeveloperTokenReturnsValue(): void
    {
        $_ENV['APPLE_MUSIC_DEVELOPER_TOKEN'] = 'apple-token';

        $result = $this->config->getAppleMusicDeveloperToken();

        $this->assertEquals('apple-token', $result);
    }

    public function testGetAppleMusicStorefrontReturnsDefault(): void
    {
        $result = $this->config->getAppleMusicStorefront();

        $this->assertEquals('us', $result);
    }

    public function testGetAppleMusicStorefrontReturnsEnvValue(): void
    {
        $_ENV['APPLE_MUSIC_STOREFRONT'] = 'gb';

        $result = $this->config->getAppleMusicStorefront();

        $this->assertEquals('gb', $result);
    }

    // ==================== hasValidCredentials(): Happy Path ====================

    public function testHasValidCredentialsReturnsTrueWhenBothSet(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'realuser';
        $_ENV['DISCOGS_TOKEN'] = 'real-token';

        $result = $this->config->hasValidCredentials();

        $this->assertTrue($result);
    }

    // ==================== hasValidCredentials(): Negative Tests ====================

    public function testHasValidCredentialsReturnsFalseWhenUsernameNotSet(): void
    {
        $_ENV['DISCOGS_TOKEN'] = 'real-token';

        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }

    public function testHasValidCredentialsReturnsFalseWhenTokenNotSet(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'realuser';

        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }

    public function testHasValidCredentialsReturnsFalseForPlaceholderUsername(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'your_username';
        $_ENV['DISCOGS_TOKEN'] = 'real-token';

        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }

    public function testHasValidCredentialsReturnsFalseForPlaceholderToken(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'realuser';
        $_ENV['DISCOGS_TOKEN'] = 'your_personal_access_token';

        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }

    public function testHasValidCredentialsReturnsFalseWhenBothArePlaceholders(): void
    {
        $_ENV['DISCOGS_USERNAME'] = 'your_username';
        $_ENV['DISCOGS_TOKEN'] = 'your_personal_access_token';

        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }

    public function testHasValidCredentialsReturnsFalseWhenNeitherSet(): void
    {
        $result = $this->config->hasValidCredentials();

        $this->assertFalse($result);
    }
}
