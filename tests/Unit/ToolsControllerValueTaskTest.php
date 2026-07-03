<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ToolsController;
use App\Http\Validation\Validator;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

final class ToolsControllerValueTaskTest extends TestCase
{
    public function testBuildsValueCommand(): void
    {
        $twig = new Environment(new ArrayLoader([]));
        $controller = new ToolsController($twig, new Validator());

        $m = new ReflectionMethod($controller, 'buildCommand');
        $m->setAccessible(true);
        $cmd = $m->invoke($controller, 'value', ['scope' => 'collection', 'force' => '1']);

        $this->assertStringContainsString('value', $cmd);
        $this->assertStringContainsString('--scope=collection', $cmd);
        $this->assertStringContainsString('--force', $cmd);
    }
}
