<?php

declare(strict_types=1);

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\ErrorCodes;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Testing\FakeTransport;

test('the list is the one SnelStart publishes', function () {
    $lines = [];

    foreach (ErrorCodes::MESSAGES as $code => $message) {
        $lines[$code] = $code.'|'.$message;
    }

    ksort($lines);

    // The checksum of https://b2bapi-developer.snelstart.nl/foutcodes-v2 when the list was taken
    // over. It fails on a typo; when SnelStart changes the page, update the list and this value.
    expect($lines)->toHaveCount(272)
        ->and(hash('sha256', implode("\n", $lines)))->toBe('e9f438797a43d9f35a2886646ff7f8fef19c1a37934b06094e8d73233c129c9b');
});

test('every code has the shape SnelStart documents and a known resource', function () {
    foreach (array_keys(ErrorCodes::MESSAGES) as $code) {
        expect($code)->toMatch('/^[A-Z]{3}-\d{4}$/')
            ->and(ErrorCodes::resource($code))->not->toBeNull()
            ->and(ErrorCodes::find('x '.$code.' y'))->toBe([$code]);
    }

    expect(array_keys(ErrorCodes::RESOURCES))->toBe(['ALG', 'ART', 'BLG', 'BOE', 'BTW', 'KPL', 'REF', 'REL', 'VOR']);
});

test('a code is looked up, and an unknown one is null', function () {
    expect(ErrorCodes::message('REL-0007'))->toBe('Naam moet gevuld zijn')
        ->and(ErrorCodes::resource('REL-0007'))->toBe('Relaties')
        ->and(ErrorCodes::message('REL-9999'))->toBeNull()
        ->and(ErrorCodes::resource('XYZ-0001'))->toBeNull();
});

test('codes are found in any text, once each, and nothing that only looks like one', function () {
    expect(ErrorCodes::find('[{"errorCode":"REL-0007","message":"Naam"},{"errorCode":"REL-0011"},{"errorCode":"REL-0007"}]'))
        ->toBe(['REL-0007', 'REL-0011'])
        ->and(ErrorCodes::find('REL-0999 is a number the list does not have yet'))->toBe(['REL-0999'])
        ->and(ErrorCodes::find('<meta charset="ISO-8859-1"> XYZ-1234, XREL-0007, 2026-10-13, REL-00071, rel-0007, NL-1234'))->toBe([])
        ->and(ErrorCodes::find(''))->toBe([]);
});

test('a failed call carries the error codes of the response, whatever its shape', function (string $body) {
    $transport = (new FakeTransport)->respond('relaties', new Response(400, $body));
    $client = new Client(new Config('client-key', 'subscription-key'), $transport);

    try {
        $client->relaties()->create(['naam' => '']);
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(400)
            ->and($e->errorCodes())->toBe(['REL-0007', 'REL-0011'])
            ->and($e->hasErrorCode('REL-0011'))->toBeTrue()
            ->and($e->hasErrorCode('REL-0005', 'REL-0007'))->toBeTrue()
            ->and($e->hasErrorCode('REL-0005'))->toBeFalse()
            ->and($e->errors())->toBe(['REL-0007' => 'Naam moet gevuld zijn', 'REL-0011' => 'Iban is ongeldig'])
            ->and($e->getMessage())->toContain('REL-0007');
    }
})->with([
    'a list of errors' => ['[{"errorCode":"REL-0007","message":"Naam moet gevuld zijn"},{"errorCode":"REL-0011","message":"Iban is ongeldig"}]'],
    'an object' => ['{"errors":{"REL-0007":"Naam","REL-0011":"Iban"}}'],
    'plain text' => ['REL-0007: Naam moet gevuld zijn. REL-0011: Iban is ongeldig.'],
]);

test('a code the package does not know is kept, with null as its text', function () {
    $transport = (new FakeTransport)->respond('relaties', new Response(400, '[{"errorCode":"REL-0999"}]'));

    try {
        (new Client(new Config('client-key'), $transport))->relaties()->list();
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->errors())->toBe(['REL-0999' => null]);
    }
});

test('a failure without codes has none', function () {
    $transport = (new FakeTransport)->respond('relaties', new Response(500, 'Internal Server Error'));

    try {
        (new Client(new Config('client-key'), $transport))->relaties()->list();
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->errorCodes())->toBe([])->and($e->errors())->toBe([])->and($e->hasErrorCode('REL-0007'))->toBeFalse();
    }

    expect((new SnelstartException('No response', 0))->errorCodes())->toBe([]);
});
