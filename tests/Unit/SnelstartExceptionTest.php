<?php

use Darvis\Snelstart\Exceptions\SnelstartException;

it('is a RuntimeException, so every existing catch keeps working', function () {
    expect(new SnelstartException('Snelstart API call failed.'))->toBeInstanceOf(RuntimeException::class);
});

it('carries the HTTP status as its code and as status()', function () {
    $exception = new SnelstartException('Snelstart API call failed. HTTP status: 429.', 429);

    expect($exception->getCode())->toBe(429)
        ->and($exception->status())->toBe(429)
        ->and($exception->getMessage())->toBe('Snelstart API call failed. HTTP status: 429.');
});

it('has status 0 when there was no response', function () {
    expect((new SnelstartException('Snelstart API config is incomplete (token_url, client_key).'))->status())->toBe(0);
});

it('keeps the previous exception', function () {
    $previous = new LogicException('cause');

    expect((new SnelstartException('message', 0, $previous))->getPrevious())->toBe($previous);
});

it('has no property a response body could end up in', function () {
    $own = array_filter(
        (new ReflectionClass(SnelstartException::class))->getProperties(),
        fn (ReflectionProperty $property) => $property->getDeclaringClass()->getName() === SnelstartException::class,
    );

    expect($own)->toBe([]);
});

it('is plain PHP, and so is the standalone client that throws it', function () {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents($root.'/src/Exceptions/SnelstartException.php'))->not->toContain('Illuminate')
        ->and(file_get_contents($root.'/src/Standalone/SnelstartAPI.php'))
        ->toContain('use Darvis\Snelstart\Exceptions\SnelstartException;')
        ->not->toContain('use Illuminate')
        ->not->toContain('use Carbon');
});

it('is the only exception the clients build themselves', function () {
    $root = dirname(__DIR__, 2);

    foreach (['/src/Services/SnelstartAPI.php', '/src/Standalone/SnelstartAPI.php'] as $file) {
        expect(file_get_contents($root.$file))->not->toContain('new \RuntimeException', $file.': throw a SnelstartException, host apps read the status from it');
    }
});
