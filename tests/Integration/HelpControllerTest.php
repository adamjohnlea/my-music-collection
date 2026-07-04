<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Http\Controllers\HelpController;
use App\Http\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Twig\Environment;

class HelpControllerTest extends TestCase
{
    /** @var array<string,mixed> */
    public array $rendered = [];

    public function testIndexRendersHelpTemplateWithTitle(): void
    {
        $twig = $this->createMock(Environment::class);
        $probe = $this;
        // Anonymous subclass captures render() instead of echoing.
        $controller = new class($twig, new Validator(), $probe) extends HelpController {
            public function __construct($twig, $v, private $probe) { parent::__construct($twig, $v); }
            protected function render(string $template, array $data = []): void
            {
                $this->probe->rendered = ['template' => $template] + $data;
            }
        };

        $controller->index();

        $this->assertSame('help.html.twig', $this->rendered['template']);
        $this->assertSame('Help', $this->rendered['title']);
    }
}
