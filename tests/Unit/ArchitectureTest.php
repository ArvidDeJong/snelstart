<?php

declare(strict_types=1);

/**
 * @return array<string, string> path relative to src => contents
 */
function sourceFiles(): array
{
    $root = dirname(__DIR__, 2).'/src';
    $files = [];

    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php') {
            $files[substr($file->getPathname(), strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
        }
    }

    ksort($files);

    return $files;
}

test('everything outside src/Laravel is plain PHP', function () {
    $checked = 0;

    foreach (sourceFiles() as $path => $code) {
        if (str_starts_with($path, 'Laravel/')) {
            continue;
        }

        $checked++;

        expect(preg_match('/\b(Illuminate|Carbon|Laravel)\\\\/', (string) preg_replace('/^namespace .*$/m', '', $code)))
            ->toBe(0, $path.' must run without Laravel');
        expect(preg_match('/(?<!->)(?<!function )\b(config|app|env|now|cache|logger)\(/', $code))->toBe(0, $path.' calls a Laravel helper');
    }

    expect($checked)->toBeGreaterThan(8);
});

test('the package config is read in one place', function () {
    foreach (sourceFiles() as $path => $code) {
        if ($path !== 'Laravel/SnelstartConfig.php') {
            // The facade names the config() method of the client in its docblock.
            $code = (string) preg_replace('#/\*.*?\*/#s', '', $code);

            expect(preg_match('/(?<!->)(?<!function )\bconfig\(/', $code))->toBe(0, $path.' must read settings through Laravel\\SnelstartConfig');
        }
    }
});

test('the client throws its own exception only', function () {
    $client = sourceFiles()['Client.php'];

    expect(substr_count($client, 'throw new SnelstartException('))->toBeGreaterThan(3)
        ->and(preg_match('/throw new (?!SnelstartException\()/', $client))->toBe(0);
});

test('every file declares strict types', function () {
    foreach (sourceFiles() as $path => $code) {
        expect(str_contains($code, 'declare(strict_types=1);'))->toBeTrue($path);
    }
});

test('the config file keeps its keys in alphabetical order', function () {
    preg_match_all("/^    '(\w+)' =>/m", (string) file_get_contents(dirname(__DIR__, 2).'/config/snelstart.php'), $keys);

    $sorted = $keys[1];
    sort($sorted);

    expect($keys[1])->toBe($sorted)->toHaveCount(8);
});
