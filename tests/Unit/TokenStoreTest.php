<?php

declare(strict_types=1);

use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Testing\FakeTransport;
use Darvis\Snelstart\Token\MemoryTokenStore;

test('the memory store keeps a token until it expires or is forgotten', function () {
    $store = new MemoryTokenStore;

    expect($store->get('a'))->toBeNull();

    $store->put('a', 'token-a', 60);
    $store->put('b', 'token-b', 0);

    expect($store->get('a'))->toBe('token-a')
        ->and($store->get('b'))->toBeNull();

    $store->forget('a');

    expect($store->get('a'))->toBeNull()
        ->and($store->synchronized('a', 5, static fn (): string => 'ran'))->toBe('ran');
});

test('the fake transport answers in turn, repeats the last answer and records what was sent', function () {
    $transport = (new FakeTransport)
        ->respond('relaties', ['first' => true], new Response(500, 'down'))
        ->respond('POST https://b2bapi.snelstart.nl/v2/relaties', ['created' => true]);

    expect($transport->send('GET', 'https://b2bapi.snelstart.nl/v2/relaties', [], null, 1, 1)->body)->toBe('{"first":true}')
        ->and($transport->send('GET', 'https://b2bapi.snelstart.nl/v2/relaties', [], null, 1, 1)->status)->toBe(500)
        ->and($transport->send('GET', 'https://b2bapi.snelstart.nl/v2/relaties', [], null, 1, 1)->status)->toBe(500)
        ->and($transport->send('POST', 'https://b2bapi.snelstart.nl/v2/relaties', [], '{}', 1, 1)->body)->toBe('{"created":true}')
        ->and($transport->send('POST', 'https://auth.snelstart.nl/b2b/token', [], '', 1, 1)->body)->toContain(FakeTransport::ACCESS_TOKEN)
        ->and($transport->sent('POST'))->toHaveCount(2)
        ->and($transport->sent())->toHaveCount(5);

    expect(fn () => $transport->send('GET', 'https://b2bapi.snelstart.nl/v2/artikelen', [], null, 1, 1))
        ->toThrow(RuntimeException::class, 'FakeTransport has no answer for GET https://b2bapi.snelstart.nl/v2/artikelen');
});
