<?php

declare(strict_types = 1);

const SUMUP_QA_ACTIONS = ['civilint', 'civilint-fix', 'javascript'];

$projectRoot = dirname(__DIR__);
$action = $argv[1] ?? '';

if (!in_array($action, SUMUP_QA_ACTIONS, TRUE)) {
  fwrite(STDERR, "Usage: php scripts/qa.php <civilint|civilint-fix|javascript>\n");
  exit(2);
}

if ($action === 'javascript') {
  $exitCode = 0;
  foreach (sumupQaFiles($projectRoot, ['js']) as $file) {
    $exitCode = max($exitCode, sumupRun(['node', '--check', $file], $projectRoot));
  }
  exit($exitCode);
}

$civilint = sumupFindCivilint($projectRoot);
if ($civilint === NULL) {
  fwrite(
    STDERR,
    "Civilint is missing. Install civicrm-buildkit beside the repository or set CIVICRM_BUILDKIT.\n",
  );
  exit(2);
}

$files = sumupQaFiles($projectRoot, ['php', 'inc', 'module', 'css']);
putenv('PHPRC=' . $projectRoot . '/tools/civilint-php.ini');
$command = [$civilint];
if ($action === 'civilint-fix') {
  $command[] = '--fix';
}
array_push($command, ...$files);
exit(sumupRun($command, $projectRoot));

/**
 * Finds source files covered by the release quality checks.
 *
 * @param string $projectRoot
 *   Absolute extension root.
 * @param list<string> $extensions
 *   File extensions to include.
 *
 * @return list<string>
 *   Absolute file paths in deterministic order.
 */
function sumupQaFiles(string $projectRoot, array $extensions): array {
  $files = [];
  $iterator = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
      new RecursiveDirectoryIterator($projectRoot, FilesystemIterator::SKIP_DOTS),
      static function (SplFileInfo $file): bool {
        if (!$file->isDir()) {
          return TRUE;
        }
        return !in_array($file->getFilename(), ['.git', 'build', 'mixin', 'vendor'], TRUE);
      },
    ),
  );
  foreach ($iterator as $file) {
    if (!$file instanceof SplFileInfo || !$file->isFile()) {
      continue;
    }
    if (!in_array($file->getExtension(), $extensions, TRUE)) {
      continue;
    }
    if ($file->getFilename() === 'sumup_payment_processor.civix.php') {
      continue;
    }
    $files[] = $file->getPathname();
  }
  sort($files);
  return $files;
}

/**
 * Finds the BuildKit Civilint executable without modifying BuildKit.
 *
 * @param string $projectRoot
 *   Absolute extension root.
 *
 * @return string|null
 *   Civilint executable path, or NULL when unavailable.
 */
function sumupFindCivilint(string $projectRoot): ?string {
  $buildkit = getenv('CIVICRM_BUILDKIT');
  if (is_string($buildkit) && $buildkit !== '') {
    $candidate = rtrim($buildkit, DIRECTORY_SEPARATOR) . '/bin/civilint';
    if (is_executable($candidate)) {
      return $candidate;
    }
  }

  $candidate = dirname($projectRoot) . '/civicrm-buildkit/bin/civilint';
  if (is_executable($candidate)) {
    return $candidate;
  }

  $path = getenv('PATH');
  if (!is_string($path)) {
    return NULL;
  }
  foreach (explode(PATH_SEPARATOR, $path) as $directory) {
    $candidate = rtrim($directory, DIRECTORY_SEPARATOR) . '/civilint';
    if (is_executable($candidate)) {
      return $candidate;
    }
  }

  return NULL;
}

/**
 * Runs one quality command without invoking a shell.
 *
 * @param list<string> $command
 *   Command and arguments.
 * @param string $workingDirectory
 *   Process working directory.
 *
 * @return int
 *   Process exit code.
 */
function sumupRun(array $command, string $workingDirectory): int {
  $descriptorSpec = [
    0 => STDIN,
    1 => STDOUT,
    2 => STDERR,
  ];
  $process = proc_open($command, $descriptorSpec, $pipes, $workingDirectory);
  if (!is_resource($process)) {
    fwrite(STDERR, "Unable to start quality command.\n");
    return 2;
  }

  return proc_close($process);
}
