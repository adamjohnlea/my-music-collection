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
            'started_at' => json_decode(file_get_contents($progressFile), true)['started_at'] ?? date('Y-m-d H:i:s'),
        ]));
    }

    if ($stderr !== false) {
        $allOutput .= $stderr;
        $output[] = rtrim($stderr);
        file_put_contents($progressFile, json_encode([
            'status' => 'running',
            'output' => $output,
            'started_at' => json_decode(file_get_contents($progressFile), true)['started_at'] ?? date('Y-m-d H:i:s'),
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
    'started_at' => json_decode(file_get_contents($progressFile), true)['started_at'] ?? date('Y-m-d H:i:s'),
    'finished_at' => date('Y-m-d H:i:s'),
]));