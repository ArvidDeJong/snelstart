<?php

declare(strict_types=1);

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Testing\FakeTransport;

function resourceClient(FakeTransport $transport): Client
{
    return new Client(new Config('client-key', 'subscription-key'), $transport);
}

/**
 * @return list<array{id: int}>
 */
function rows(int $from, int $count): array
{
    return array_map(static fn (int $id): array => ['id' => $id], $count > 0 ? range($from, $from + $count - 1) : []);
}

test('list is one call with the query as given', function () {
    $transport = (new FakeTransport)->respond('relaties', rows(1, 2));

    expect(resourceClient($transport)->relaties()->list(['$top' => 2]))->toBe(rows(1, 2))
        ->and($transport->sent('relaties'))->toHaveCount(1)
        ->and($transport->sent('relaties')[0]['url'])->toBe('https://b2bapi.snelstart.nl/v2/relaties?%24top=2');
});

test('all walks the pages until a page is not full, and keeps the filter', function () {
    $transport = (new FakeTransport)->respond('relaties', rows(1, 2), rows(3, 2), rows(5, 1));

    $all = iterator_to_array(resourceClient($transport)->relaties()->all(['$filter' => 'nonactief eq false'], 2));

    expect($all)->toBe(rows(1, 5))
        ->and(array_column($transport->sent('relaties'), 'url'))->toBe([
            'https://b2bapi.snelstart.nl/v2/relaties?%24skip=0&%24top=2&%24filter=nonactief%20eq%20false',
            'https://b2bapi.snelstart.nl/v2/relaties?%24skip=2&%24top=2&%24filter=nonactief%20eq%20false',
            'https://b2bapi.snelstart.nl/v2/relaties?%24skip=4&%24top=2&%24filter=nonactief%20eq%20false',
        ]);
});

test('all fetches one more page when the last one was exactly full, and stops on the empty one', function () {
    $transport = (new FakeTransport)->respond('artikelen', rows(1, 2), []);

    expect(iterator_to_array(resourceClient($transport)->artikelen()->all([], 2)))->toHaveCount(2)
        ->and($transport->sent('artikelen'))->toHaveCount(2);
});

test('all starts at the $skip you give and asks for 500 rows at a time by default', function () {
    $transport = (new FakeTransport)->respond('verkooporders', rows(1, 1));

    iterator_to_array(resourceClient($transport)->verkooporders()->all(['$skip' => 40, '$top' => 3]));

    expect($transport->sent('verkooporders')[0]['url'])->toEndWith('/verkooporders?%24skip=40&%24top=500');
});

test('all fetches no further page once you stop looping', function () {
    $transport = (new FakeTransport)->respond('relaties', rows(1, 2));

    foreach (resourceClient($transport)->relaties()->all([], 2) as $row) {
        break;
    }

    expect($transport->sent('relaties'))->toHaveCount(1);
});

test('all on a collection without paging is one call without $skip and $top', function () {
    $transport = (new FakeTransport)->respond('landen', rows(1, 600));

    expect(iterator_to_array(resourceClient($transport)->landen()->all()))->toHaveCount(600)
        ->and($transport->sent('landen'))->toHaveCount(1)
        ->and($transport->sent('landen')[0]['url'])->toBe('https://b2bapi.snelstart.nl/v2/landen');
});

test('find, create, update and delete call the row', function () {
    $transport = (new FakeTransport)->respond('relaties', ['id' => 'a b', 'naam' => 'Jansen']);
    $relaties = resourceClient($transport)->relaties();

    expect($relaties->find('a b'))->toBe(['id' => 'a b', 'naam' => 'Jansen'])
        ->and($relaties->create(['naam' => 'Jansen']))->toBe(['id' => 'a b', 'naam' => 'Jansen'])
        ->and($relaties->update('a b', ['naam' => 'Jansen BV']))->toBe(['id' => 'a b', 'naam' => 'Jansen'])
        ->and($relaties->delete('a b'))->toBeNull();

    expect(array_map(static fn (array $r): string => $r['method'].' '.$r['url'].' '.$r['body'], $transport->sent('relaties')))->toBe([
        'GET https://b2bapi.snelstart.nl/v2/relaties/a%20b ',
        'POST https://b2bapi.snelstart.nl/v2/relaties {"naam":"Jansen"}',
        'PUT https://b2bapi.snelstart.nl/v2/relaties/a%20b {"naam":"Jansen BV"}',
        'DELETE https://b2bapi.snelstart.nl/v2/relaties/a%20b ',
    ]);
});

test('a response that is not a list or a row gives an empty array', function () {
    $transport = (new FakeTransport)->respond('relaties', new Response(200, '"text"'));
    $relaties = resourceClient($transport)->relaties();

    expect($relaties->list())->toBe([])
        ->and($relaties->find('x'))->toBe([])
        ->and($relaties->create(['naam' => 'x']))->toBe([]);
});

test('a failure in a resource is the same SnelstartException', function () {
    $transport = (new FakeTransport)->respond('relaties', new Response(404, ''));

    expect(fn () => resourceClient($transport)->relaties()->find('missing'))
        ->toThrow(function (SnelstartException $e) {
            expect($e->status())->toBe(404)->and($e->getMessage())->toBe('Snelstart API call failed. HTTP status: 404.');
        });
});

test('every named collection points at its path', function (string $method, bool $paged) {
    $transport = (new FakeTransport)->respond('b2bapi', []);

    iterator_to_array(resourceClient($transport)->{$method}()->all());

    expect($transport->sent('b2bapi')[0]['url'])->toBe(
        'https://b2bapi.snelstart.nl/v2/'.$method.($paged ? '?%24skip=0&%24top=500' : '')
    );
})->with([
    ['actieprijzen', true],
    ['artikelen', true],
    ['artikelomzetgroepen', false],
    ['bankboekingen', true],
    ['btwaangiftes', true],
    ['btwtarieven', false],
    ['dagboeken', false],
    ['documenten', false],
    ['grootboeken', true],
    ['grootboekmutaties', true],
    ['inkoopboekingen', false],
    ['inkoopfacturen', true],
    ['kasboekingen', true],
    ['kostenplaatsen', false],
    ['landen', false],
    ['memoriaalboekingen', false],
    ['offertes', true],
    ['prijsafspraken', true],
    ['relaties', true],
    ['vatratedefinitions', true],
    ['vatrates', true],
    ['verkoopboekingen', false],
    ['verkoopfacturen', true],
    ['verkooporders', true],
    ['verkoopordersjablonen', false],
]);

test('resource reaches a collection that has no method of its own', function () {
    $transport = (new FakeTransport)->respond('b2bapi', []);

    resourceClient($transport)->resource('/artikelen/prijsafspraken/')->list();

    expect($transport->sent('b2bapi')[0]['url'])->toBe('https://b2bapi.snelstart.nl/v2/artikelen/prijsafspraken');
});

test('list gives an empty array for a response that is an object, not a list', function () {
    $transport = (new FakeTransport)->respond('relaties', ['value' => [1, 2], 'count' => 2]);

    expect(resourceClient($transport)->relaties()->list())->toBe([]);
});

test('all stops with an error when a collection returns the same page twice', function () {
    $transport = (new FakeTransport)->respond('b2bapi', rows(1, 2));
    $seen = [];

    try {
        foreach (resourceClient($transport)->resource('landen')->all([], 2) as $row) {
            $seen[] = $row;
        }

        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getMessage())->toStartWith('Snelstart paging does not advance: "landen" returned the same page twice.')
            ->and($seen)->toBe(rows(1, 2))
            ->and($transport->sent('landen'))->toHaveCount(2);
    }
});
