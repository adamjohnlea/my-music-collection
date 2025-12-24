<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Validation\Validator;
use PHPUnit\Framework\TestCase;

class ValidatorTest extends TestCase
{
    private Validator $validator;

    protected function setUp(): void
    {
        $this->validator = new Validator();
    }

    public function testRequiredRule(): void
    {
        $data = ['name' => ''];
        $rules = ['name' => 'required'];
        $this->assertFalse($this->validator->validate($data, $rules));
        $this->assertArrayHasKey('name', $this->validator->getErrors());

        $data = ['name' => 'John'];
        $this->assertTrue($this->validator->validate($data, $rules));
    }

    public function testEmailRule(): void
    {
        $data = ['email' => 'invalid-email'];
        $rules = ['email' => 'email'];
        $this->assertFalse($this->validator->validate($data, $rules));

        $data = ['email' => 'test@example.com'];
        $this->assertTrue($this->validator->validate($data, $rules));
    }

    public function testNumericRule(): void
    {
        $data = ['age' => 'abc'];
        $rules = ['age' => 'numeric'];
        $this->assertFalse($this->validator->validate($data, $rules));

        $data = ['age' => '25'];
        $this->assertTrue($this->validator->validate($data, $rules));
    }

    public function testMinRule(): void
    {
        $data = ['password' => '123'];
        $rules = ['password' => 'min:6'];
        $this->assertFalse($this->validator->validate($data, $rules));

        $data = ['password' => '123456'];
        $this->assertTrue($this->validator->validate($data, $rules));
    }
}
