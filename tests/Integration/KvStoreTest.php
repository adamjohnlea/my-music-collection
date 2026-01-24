<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Infrastructure\KvStore;
use PHPUnit\Framework\TestCase;
use PDO;

class KvStoreTest extends TestCase
{
    private PDO $pdo;
    private KvStore $kv;

    protected function setUp(): void
    {
        parent::setUp();

        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec('CREATE TABLE kv_store (k TEXT PRIMARY KEY, v TEXT)');

        $this->kv = new KvStore($this->pdo);
    }

    // ==================== get(): Happy Path ====================

    public function testGetReturnsStoredValue(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO kv_store (k, v) VALUES ('mykey', 'myvalue')");

        // Act
        $result = $this->kv->get('mykey');

        // Assert
        $this->assertEquals('myvalue', $result);
    }

    public function testGetReturnsDefaultWhenKeyNotFound(): void
    {
        // Act
        $result = $this->kv->get('nonexistent', 'default-value');

        // Assert
        $this->assertEquals('default-value', $result);
    }

    public function testGetReturnsNullWhenKeyNotFoundAndNoDefault(): void
    {
        // Act
        $result = $this->kv->get('nonexistent');

        // Assert
        $this->assertNull($result);
    }

    // ==================== get(): Edge Cases ====================

    public function testGetWithEmptyStringValue(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO kv_store (k, v) VALUES ('empty', '')");

        // Act
        $result = $this->kv->get('empty', 'default');

        // Assert
        $this->assertEquals('', $result);
    }

    public function testGetWithEmptyStringKey(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO kv_store (k, v) VALUES ('', 'empty-key-value')");

        // Act
        $result = $this->kv->get('');

        // Assert
        $this->assertEquals('empty-key-value', $result);
    }

    public function testGetWithSpecialCharactersInKey(): void
    {
        // Arrange
        $key = "key:with:colons:and/slashes";
        $this->pdo->exec("INSERT INTO kv_store (k, v) VALUES ('$key', 'special')");

        // Act
        $result = $this->kv->get($key);

        // Assert
        $this->assertEquals('special', $result);
    }

    public function testGetWithNumericStringValue(): void
    {
        // Arrange
        $this->pdo->exec("INSERT INTO kv_store (k, v) VALUES ('number', '12345')");

        // Act
        $result = $this->kv->get('number');

        // Assert
        $this->assertEquals('12345', $result);
        $this->assertIsString($result);
    }

    // ==================== set(): Happy Path ====================

    public function testSetStoresValue(): void
    {
        // Act
        $this->kv->set('newkey', 'newvalue');

        // Assert
        $stmt = $this->pdo->query("SELECT v FROM kv_store WHERE k = 'newkey'");
        $this->assertEquals('newvalue', $stmt->fetchColumn());
    }

    public function testSetOverwritesExistingValue(): void
    {
        // Arrange
        $this->kv->set('key', 'original');

        // Act
        $this->kv->set('key', 'updated');

        // Assert
        $this->assertEquals('updated', $this->kv->get('key'));

        // Verify only one row exists
        $count = (int)$this->pdo->query("SELECT COUNT(*) FROM kv_store WHERE k = 'key'")->fetchColumn();
        $this->assertEquals(1, $count);
    }

    // ==================== set(): Edge Cases ====================

    public function testSetWithEmptyStringValue(): void
    {
        // Act
        $this->kv->set('key', '');

        // Assert
        $this->assertEquals('', $this->kv->get('key'));
    }

    public function testSetWithEmptyStringKey(): void
    {
        // Act
        $this->kv->set('', 'value-for-empty-key');

        // Assert
        $this->assertEquals('value-for-empty-key', $this->kv->get(''));
    }

    public function testSetWithLongValue(): void
    {
        // Arrange
        $longValue = str_repeat('a', 10000);

        // Act
        $this->kv->set('longkey', $longValue);

        // Assert
        $this->assertEquals($longValue, $this->kv->get('longkey'));
    }

    public function testSetWithJsonValue(): void
    {
        // Arrange
        $json = json_encode(['foo' => 'bar', 'nested' => ['a' => 1]]);

        // Act
        $this->kv->set('json', $json);

        // Assert
        $this->assertEquals($json, $this->kv->get('json'));
    }

    // ==================== incr(): Happy Path ====================

    public function testIncrIncrementsExistingValue(): void
    {
        // Arrange
        $this->kv->set('counter', '5');

        // Act
        $result = $this->kv->incr('counter');

        // Assert
        $this->assertEquals(6, $result);
        $this->assertEquals('6', $this->kv->get('counter'));
    }

    public function testIncrCreatesKeyIfNotExists(): void
    {
        // Act
        $result = $this->kv->incr('newcounter');

        // Assert
        $this->assertEquals(1, $result);
        $this->assertEquals('1', $this->kv->get('newcounter'));
    }

    public function testIncrWithCustomIncrement(): void
    {
        // Arrange
        $this->kv->set('counter', '10');

        // Act
        $result = $this->kv->incr('counter', 5);

        // Assert
        $this->assertEquals(15, $result);
    }

    public function testIncrMultipleTimes(): void
    {
        // Act
        $this->kv->incr('counter');
        $this->kv->incr('counter');
        $result = $this->kv->incr('counter');

        // Assert
        $this->assertEquals(3, $result);
    }

    // ==================== incr(): Edge Cases ====================

    public function testIncrWithZeroIncrement(): void
    {
        // Arrange
        $this->kv->set('counter', '5');

        // Act
        $result = $this->kv->incr('counter', 0);

        // Assert
        $this->assertEquals(5, $result);
    }

    public function testIncrWithNegativeIncrement(): void
    {
        // Arrange
        $this->kv->set('counter', '10');

        // Act
        $result = $this->kv->incr('counter', -3);

        // Assert
        $this->assertEquals(7, $result);
    }

    public function testIncrWithNonNumericExistingValue(): void
    {
        // Arrange
        $this->kv->set('counter', 'not-a-number');

        // Act
        $result = $this->kv->incr('counter');

        // Assert
        // PHP (int) cast of 'not-a-number' is 0, so result is 1
        $this->assertEquals(1, $result);
    }

    public function testIncrReturnsInteger(): void
    {
        // Act
        $result = $this->kv->incr('counter');

        // Assert
        $this->assertIsInt($result);
    }

    // ==================== Integration: Multiple Keys ====================

    public function testMultipleKeysAreIndependent(): void
    {
        // Act
        $this->kv->set('key1', 'value1');
        $this->kv->set('key2', 'value2');
        $this->kv->set('key3', 'value3');

        // Assert
        $this->assertEquals('value1', $this->kv->get('key1'));
        $this->assertEquals('value2', $this->kv->get('key2'));
        $this->assertEquals('value3', $this->kv->get('key3'));
    }

    public function testSetDoesNotAffectOtherKeys(): void
    {
        // Arrange
        $this->kv->set('key1', 'original1');
        $this->kv->set('key2', 'original2');

        // Act
        $this->kv->set('key1', 'updated1');

        // Assert
        $this->assertEquals('updated1', $this->kv->get('key1'));
        $this->assertEquals('original2', $this->kv->get('key2'));
    }
}
