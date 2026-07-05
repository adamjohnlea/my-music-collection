<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Http\Controllers\ToolsController;
use PHPUnit\Framework\TestCase;

final class ToolsControllerPosterTaskTest extends TestCase
{
    public function testBuildsPosterCommandWithOrderAndResolution(): void
    {
        $cmd = ToolsController::buildPosterCommandString([
            'order' => 'color',
            'resolution' => '5000',
            'format' => 'png',
        ]);
        $this->assertStringContainsString('poster:generate', $cmd);
        $this->assertStringContainsString('--order=color', $cmd);
        $this->assertStringContainsString('--resolution=5000', $cmd);
        $this->assertStringContainsString('--format=png', $cmd);
    }

    public function testRejectsUnknownOrderAndClampsResolution(): void
    {
        $cmd = ToolsController::buildPosterCommandString([
            'order' => 'bogus; rm -rf /',
            'resolution' => '999999',
        ]);
        $this->assertStringNotContainsString('rm -rf', $cmd);
        $this->assertStringContainsString('--order=added', $cmd);   // fell back to default
        $this->assertStringContainsString('--resolution=7200', $cmd); // clamped
    }
}
