<?php
declare(strict_types=1);

namespace App\Http\Controllers;

use App\Infrastructure\Config;
use Twig\Environment;
use App\Http\Validation\Validator;

class ToolsController extends BaseController
{
    private string $progressDir;

    public function __construct(
        Environment $twig,
        Validator $validator,
        private Config $config
    ) {
        parent::__construct($twig, $validator);
        $this->progressDir = dirname(__DIR__, 3) . '/var/progress';
        if (!is_dir($this->progressDir)) {
            mkdir($this->progressDir, 0755, true);
        }
    }

    public function index(): void
    {
        $this->render('tools.html.twig', [
            'title' => 'Tools - Console Commands',
        ]);
    }

    public function run(): void
    {
        // CSRF validation
        $token = $_POST['_token'] ?? '';
        if (!hash_equals($_SESSION['csrf'] ?? '', $token)) {
            http_response_code(403);
            echo json_encode(['error' => 'Invalid CSRF token']);
            return;
        }

        $task = $_POST['task'] ?? '';
        $allowedTasks = ['initial', 'refresh', 'enrich', 'images', 'search', 'push', 'export'];

        if (!in_array($task, $allowedTasks)) {
            http_response_code(400);
            echo json_encode(['error' => 'Invalid task']);
            return;
        }

        // Generate a unique job ID
        $jobId = uniqid('job_', true);
        $progressFile = $this->progressDir . '/' . $jobId . '.json';

        // Build the command
        $command = $this->buildCommand($task, $_POST);

        // Initialize progress file
        $this->updateProgress($jobId, [
            'status' => 'running',
            'output' => [],
            'started_at' => date('Y-m-d H:i:s'),
            'command' => $command,
        ]);

        // Execute command in background
        $this->executeInBackground($command, $jobId);

        // Return job ID for polling
        header('Content-Type: application/json');
        echo json_encode([
            'job_id' => $jobId,
            'status' => 'started',
        ]);
    }

    public function progress(string $jobId): void
    {
        $progressFile = $this->progressDir . '/' . $jobId . '.json';

        if (!file_exists($progressFile)) {
            http_response_code(404);
            echo json_encode(['error' => 'Job not found']);
            return;
        }

        $data = json_decode(file_get_contents($progressFile), true);

        header('Content-Type: application/json');
        echo json_encode($data);
    }

    private function buildCommand(string $task, array $params): string
    {
        $basePath = dirname(__DIR__, 3);
        $console = $basePath . '/bin/console';

        $command = match($task) {
            'initial' => 'sync:initial' . (isset($params['force']) ? ' --force' : ''),
            'refresh' => 'sync:refresh' . (isset($params['pages']) ? ' --pages=' . (int)$params['pages'] : ''),
            'enrich' => $this->buildEnrichCommand($params),
            'images' => 'images:backfill' . (isset($params['limit']) ? ' --limit=' . (int)$params['limit'] : ''),
            'search' => 'search:rebuild',
            'push' => 'sync:push',
            'export' => $this->buildExportCommand($params),
            default => throw new \InvalidArgumentException('Unknown task: ' . $task),
        };

        return sprintf('php %s %s 2>&1', escapeshellarg($console), $command);
    }

    private function buildEnrichCommand(array $params): string
    {
        $cmd = 'sync:enrich';
        if (!empty($params['release_id'])) {
            $cmd .= ' --id=' . (int)$params['release_id'];
        } elseif (!empty($params['limit'])) {
            $cmd .= ' --limit=' . (int)$params['limit'];
        }
        return $cmd;
    }

    private function buildExportCommand(array $params): string
    {
        $cmd = 'export:static';
        if (!empty($params['out'])) {
            $cmd .= ' --out=' . escapeshellarg($params['out']);
        }
        if (!empty($params['base_url'])) {
            $cmd .= ' --base-url=' . escapeshellarg($params['base_url']);
        }
        if (isset($params['copy_images'])) {
            $cmd .= ' --copy-images';
        }
        if (!empty($params['chunk_size'])) {
            $cmd .= ' --chunk-size=' . (int)$params['chunk_size'];
        }
        return $cmd;
    }

    private function executeInBackground(string $command, string $jobId): void
    {
        $progressFile = $this->progressDir . '/' . $jobId . '.json';
        $outputFile = $this->progressDir . '/' . $jobId . '.log';

        // Create a PHP script that will execute the command and update progress
        $scriptPath = $this->progressDir . '/' . $jobId . '.php';
        $script = <<<'PHP'
<?php
$command = $argv[1];
$jobId = $argv[2];
$progressFile = $argv[3];
$outputFile = $argv[4];

// Open process
$descriptorspec = [
    0 => ['pipe', 'r'],
    1 => ['pipe', 'w'],
    2 => ['pipe', 'w'],
];

$process = proc_open($command, $descriptorspec, $pipes);

if (!is_resource($process)) {
    file_put_contents($progressFile, json_encode([
        'status' => 'error',
        'output' => ['Failed to start process'],
        'finished_at' => date('Y-m-d H:i:s'),
    ]));
    exit(1);
}

fclose($pipes[0]);

$output = [];
$allOutput = '';

// Get the started_at timestamp once at the beginning
$initialData = @json_decode(@file_get_contents($progressFile), true);
$startedAt = $initialData['started_at'] ?? date('Y-m-d H:i:s');

// Read output in real-time
stream_set_blocking($pipes[1], false);
stream_set_blocking($pipes[2], false);

while (true) {
    $stdout = fgets($pipes[1]);
    $stderr = fgets($pipes[2]);

    if ($stdout !== false) {
        $allOutput .= $stdout;
        $output[] = rtrim($stdout);
        file_put_contents($progressFile, json_encode([
            'status' => 'running',
            'output' => $output,
            'started_at' => $startedAt,
        ]));
    }

    if ($stderr !== false) {
        $allOutput .= $stderr;
        $output[] = rtrim($stderr);
        file_put_contents($progressFile, json_encode([
            'status' => 'running',
            'output' => $output,
            'started_at' => $startedAt,
        ]));
    }

    $status = proc_get_status($process);
    if (!$status['running']) {
        break;
    }

    usleep(100000); // 100ms
}

// Get any remaining output
while ($line = fgets($pipes[1])) {
    $allOutput .= $line;
    $output[] = rtrim($line);
}
while ($line = fgets($pipes[2])) {
    $allOutput .= $line;
    $output[] = rtrim($line);
}

fclose($pipes[1]);
fclose($pipes[2]);

$exitCode = proc_close($process);

// Save final output
file_put_contents($outputFile, $allOutput);

// Update final status
file_put_contents($progressFile, json_encode([
    'status' => $exitCode === 0 ? 'completed' : 'error',
    'output' => $output,
    'exit_code' => $exitCode,
    'started_at' => $startedAt,
    'finished_at' => date('Y-m-d H:i:s'),
]));
PHP;

        file_put_contents($scriptPath, $script);
        chmod($scriptPath, 0755);

        // Execute the script in the background using nohup for better reliability
        $cmd = sprintf(
            'nohup php %s %s %s %s %s > /dev/null 2>&1 & echo $!',
            escapeshellarg($scriptPath),
            escapeshellarg($command),
            escapeshellarg($jobId),
            escapeshellarg($progressFile),
            escapeshellarg($outputFile)
        );

        $pid = exec($cmd);

        // Store the PID so we can check if it's running
        if ($pid) {
            $progressData = json_decode(file_get_contents($progressFile), true);
            $progressData['pid'] = $pid;
            file_put_contents($progressFile, json_encode($progressData));
        }
    }

    private function updateProgress(string $jobId, array $data): void
    {
        $progressFile = $this->progressDir . '/' . $jobId . '.json';
        file_put_contents($progressFile, json_encode($data));
    }
}
