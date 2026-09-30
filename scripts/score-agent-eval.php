<?php

declare(strict_types=1);

/*
 * Score agent-authored SDK eval answers on static analysis and semantic markers.
 *
 * Usage: php scripts/score-agent-eval.php [answer-directory]
 */

$packageRoot = dirname(__DIR__);
$answerDirectory = realpath($argv[1] ?? 'evals/reference');
if ($answerDirectory === false) {
    fwrite(STDERR, "Answer directory not found.\n");
    exit(1);
}

/** @var list<array{id: string, required: list<string>, forbidden?: list<string>}> $tasks */
$tasks = json_decode((string) file_get_contents("{$packageRoot}/evals/tasks.json"), true, 512, JSON_THROW_ON_ERROR);

$command = sprintf(
    '%s %s analyse --level=max --no-progress --error-format=raw --autoload-file=%s %s 2>&1',
    escapeshellarg(PHP_BINARY),
    escapeshellarg("{$packageRoot}/vendor/bin/phpstan"),
    escapeshellarg("{$packageRoot}/vendor/autoload.php"),
    escapeshellarg($answerDirectory),
);
exec($command, $output, $status);
$failingFiles = [];
foreach ($output as $line) {
    $location = explode(':', $line, 2)[0];
    if (str_ends_with($location, '.php')) {
        $failingFiles[realpath($location) ?: $location] = true;
    }
}
if ($status !== 0) {
    echo implode("\n", $output), "\n";
}

$compileScore = 0;
$semanticScore = 0;
foreach ($tasks as $task) {
    $answer = "{$answerDirectory}/{$task['id']}.php";
    if (!is_file($answer)) {
        echo "{$task['id']}: compile=fail semantic=fail (missing file)\n";
        continue;
    }
    $source = (string) file_get_contents($answer);
    $compiles = $status !== 0 && $failingFiles === [] ? false : !isset($failingFiles[realpath($answer)]);
    $required = array_filter($task['required'], static fn(string $marker): bool => !str_contains($source, $marker)) === [];
    $forbidden = array_filter($task['forbidden'] ?? [], static fn(string $marker): bool => str_contains($source, $marker)) === [];
    $usesPublicPackage = str_contains($source, 'use OpenHandle\\') && !str_contains($source, 'OpenHandle\\Internal');
    $semantic = $required && $forbidden && $usesPublicPackage;
    $compileScore += $compiles ? 1 : 0;
    $semanticScore += $semantic ? 1 : 0;
    echo "{$task['id']}: compile=" . ($compiles ? 'pass' : 'fail') . ' semantic=' . ($semantic ? 'pass' : 'fail') . "\n";
}

$total = count($tasks);
echo "Agent SDK eval: compile {$compileScore}/{$total}, semantic {$semanticScore}/{$total}\n";
exit($compileScore === $total && $semanticScore === $total ? 0 : 1);
