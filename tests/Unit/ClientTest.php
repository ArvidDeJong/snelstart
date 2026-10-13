<?php

declare(strict_types=1);

use Darvis\Snelstart\Client;
use Darvis\Snelstart\Config;
use Darvis\Snelstart\Exceptions\SnelstartException;
use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Http\Transport;
use Darvis\Snelstart\Testing\FakeTransport;
use Darvis\Snelstart\Token\MemoryTokenStore;

const CLIENT_KEY = 'test-client-key/with+base64==';
const SUBSCRIPTION_KEY = 'test-subscription-key';

function client(FakeTransport $transport, string $clientKey = CLIENT_KEY): Client
{
    return new Client(new Config($clientKey, SUBSCRIPTION_KEY), $transport);
}

function token(string $token, int $expiresIn = 3600): Response
{
    return new Response(200, (string) json_encode(['access_token' => $token, 'expires_in' => $expiresIn]));
}

test('a call fetches a token with the client key in the form body and sends it with the subscription key', function () {
    $transport = (new FakeTransport)->respond('companyInfo', ['administratieNaam' => 'Test']);

    expect(client($transport)->companyInfo())->toBe(['administratieNaam' => 'Test']);

    [$tokenRequest, $apiRequest] = $transport->requests;

    expect($tokenRequest['method'])->toBe('POST')
        ->and($tokenRequest['url'])->toBe('https://auth.snelstart.nl/b2b/token')
        ->and($tokenRequest['headers']['Content-Type'])->toBe('application/x-www-form-urlencoded')
        ->and($tokenRequest['body'])->toBe('grant_type=clientkey&clientkey='.urlencode(CLIENT_KEY));

    expect($apiRequest['method'])->toBe('GET')
        ->and($apiRequest['url'])->toBe('https://b2bapi.snelstart.nl/v2/companyInfo')
        ->and($apiRequest['headers'])->toBe([
            'Accept' => 'application/json',
            'Authorization' => 'Bearer '.FakeTransport::ACCESS_TOKEN,
            'Ocp-Apim-Subscription-Key' => SUBSCRIPTION_KEY,
        ])
        ->and($apiRequest['body'])->toBeNull();
});

test('no key and no token ever ends up in a URL', function () {
    $transport = (new FakeTransport)->respond('b2bapi', []);
    $client = client($transport);

    $client->get('relaties', ['$top' => 1]);
    $client->post('relaties', ['naam' => 'Jansen']);
    $client->echo();

    foreach ($transport->requests as $request) {
        expect($request['url'])
            ->not->toContain(CLIENT_KEY)
            ->not->toContain(urlencode(CLIENT_KEY))
            ->not->toContain(SUBSCRIPTION_KEY)
            ->not->toContain(FakeTransport::ACCESS_TOKEN);
    }
});

test('the token is fetched once and used for every call after it', function () {
    $transport = (new FakeTransport)->respond('b2bapi', []);
    $client = client($transport);

    $client->get('relaties');
    $client->get('artikelen');
    $client->get('landen');

    expect($transport->sent('/token'))->toHaveCount(1)
        ->and($transport->sent('b2bapi'))->toHaveCount(3);
});

test('a token that expires within the margin is used once and not kept', function () {
    $transport = (new FakeTransport)->respond('/token', token('short-lived', 60))->respond('b2bapi', []);
    $client = client($transport);

    $client->get('relaties');
    $client->get('relaties');

    expect($transport->sent('/token'))->toHaveCount(2);
});

test('two administrations share the transport and the store, and each gets its own token', function () {
    $transport = (new FakeTransport)
        ->respond('/token', token('token-nl'), token('token-be'))
        ->respond('b2bapi', []);

    $nl = new Client(new Config('key-nl', SUBSCRIPTION_KEY), $transport, new MemoryTokenStore);
    $be = $nl->withClientKey('key-be');

    $nl->get('relaties');
    $be->get('relaties');
    $nl->get('artikelen');
    $be->get('artikelen');

    expect($transport->sent('/token'))->toHaveCount(2)
        ->and($transport->sent('/token')[1]['body'])->toContain('clientkey=key-be')
        ->and(array_column(array_column($transport->sent('b2bapi'), 'headers'), 'Authorization'))
        ->toBe(['Bearer token-nl', 'Bearer token-be', 'Bearer token-nl', 'Bearer token-be'])
        ->and($be->config()->subscriptionKey)->toBe(SUBSCRIPTION_KEY);
});

test('the query is encoded once, with OData options and booleans as words', function () {
    $transport = (new FakeTransport)->respond('b2bapi', []);

    client($transport)->get('/relaties', ['$filter' => "Relatiesoort/any(r:r eq 'Klant')", '$top' => 10, 'actief' => true, 'oud' => false]);

    expect($transport->sent('b2bapi')[0]['url'])->toBe(
        'https://b2bapi.snelstart.nl/v2/relaties?%24filter=Relatiesoort%2Fany%28r%3Ar%20eq%20%27Klant%27%29&%24top=10&actief=true&oud=false'
    );
});

test('post and put send JSON, and an empty array sends no body', function () {
    $transport = (new FakeTransport)->respond('b2bapi', ['id' => 'abc']);
    $client = client($transport);

    $client->post('relaties', ['naam' => 'Café/Bar', 'relatiesoort' => ['Klant']]);
    $client->put('relaties/abc', [['name' => 'x']], ['dryRun' => true]);
    $client->post('inkoopboekingen/ubl');

    [$post, $put, $empty] = $transport->sent('b2bapi');

    expect($post['body'])->toBe('{"naam":"Café/Bar","relatiesoort":["Klant"]}')
        ->and($post['headers']['Content-Type'])->toBe('application/json')
        ->and($put['method'])->toBe('PUT')
        ->and($put['url'])->toEndWith('/relaties/abc?dryRun=true')
        ->and($put['body'])->toBe('[{"name":"x"}]')
        ->and($empty['body'])->toBeNull()
        ->and($empty['headers'])->not->toHaveKey('Content-Type');
});

test('a response is decoded: null when empty, the value when JSON, the raw body otherwise', function () {
    $transport = (new FakeTransport)
        ->respond('empty', new Response(204))
        ->respond('scalar', new Response(200, '"pong"'))
        ->respond('xml', new Response(200, '<Invoice/>'))
        ->respond('list', [['id' => 1]]);
    $client = client($transport);

    expect($client->delete('empty'))->toBeNull()
        ->and($client->get('scalar'))->toBe('pong')
        ->and($client->get('xml'))->toBe('<Invoice/>')
        ->and($client->get('list'))->toBe([['id' => 1]]);
});

test('a 401 on a kept token fetches a new token and repeats the call once', function () {
    $transport = (new FakeTransport)
        ->respond('/token', token('old'), token('new'))
        ->respond('relaties', [], new Response(401, 'expired'), ['ok' => true]);
    $client = client($transport);

    $client->get('relaties');

    expect($client->get('relaties'))->toBe(['ok' => true])
        ->and($transport->sent('/token'))->toHaveCount(2)
        ->and(array_column(array_column($transport->sent('relaties'), 'headers'), 'Authorization'))
        ->toBe(['Bearer old', 'Bearer old', 'Bearer new']);
});

test('a 401 on a token of a moment ago is not repeated', function () {
    $transport = (new FakeTransport)->respond('relaties', new Response(401, 'no access'));

    expect(fn () => client($transport)->get('relaties'))->toThrow(SnelstartException::class, 'HTTP status: 401')
        ->and($transport->sent('/token'))->toHaveCount(1)
        ->and($transport->sent('relaties'))->toHaveCount(1);
});

test('a second 401 after the repeat is thrown, and nothing is tried a third time', function () {
    $transport = (new FakeTransport)->respond('relaties', [], new Response(401, 'still no'));
    $client = client($transport);

    $client->get('relaties');

    expect(fn () => $client->get('relaties'))->toThrow(SnelstartException::class)
        ->and($transport->sent('/token'))->toHaveCount(2)
        ->and($transport->sent('relaties'))->toHaveCount(3);
});

test('nothing but a 401 is repeated', function (int $status) {
    $transport = (new FakeTransport)->respond('relaties', [], new Response($status, 'nope'));
    $client = client($transport);

    $client->get('relaties');

    expect(fn () => $client->post('relaties', ['naam' => 'x']))->toThrow(SnelstartException::class)
        ->and($transport->sent('relaties'))->toHaveCount(2);
})->with([400, 403, 404, 429, 500, 503]);

test('a failed call throws with the status, and the message has the response without the keys', function () {
    $echoed = json_encode(['error' => 'bad', 'key' => CLIENT_KEY, 'form' => urlencode(CLIENT_KEY), 'sub' => SUBSCRIPTION_KEY, 'token' => FakeTransport::ACCESS_TOKEN]);
    $transport = (new FakeTransport)->respond('relaties', new Response(429, (string) $echoed));

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(429)
            ->and($e->getCode())->toBe(429)
            ->and($e)->toBeInstanceOf(RuntimeException::class)
            ->and($e->getMessage())->toStartWith('Snelstart API call failed. HTTP status: 429. Response: {"error":"bad"')
            ->and($e->getMessage())->toContain('[redacted]')
            ->not->toContain(CLIENT_KEY)
            ->not->toContain(trim((string) json_encode(CLIENT_KEY), '"'))
            ->not->toContain(urlencode(CLIENT_KEY))
            ->not->toContain(SUBSCRIPTION_KEY)
            ->not->toContain(FakeTransport::ACCESS_TOKEN);
    }
});

test('a refused client key says so, with the status of the token endpoint', function () {
    $transport = (new FakeTransport)->respond('/token', new Response(400, '{"error":"invalid_grant"}'));

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(400)
            ->and($e->getMessage())->toBe('Failed to retrieve access_token from Snelstart. HTTP status: 400. Response: {"error":"invalid_grant"}')
            ->and($transport->sent('b2bapi'))->toBeEmpty();
    }
});

test('a token response without a token is an error with the status of that response', function () {
    $transport = (new FakeTransport)->respond('/token', ['token_type' => 'bearer']);

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(200)
            ->and($e->getMessage())->toBe('Snelstart token response does not contain access_token.');
    }
});

test('without a client key nothing is sent', function () {
    $transport = new FakeTransport;

    try {
        client($transport, '')->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getMessage())->toBe('Snelstart config is incomplete: the client key is empty.')
            ->and($transport->requests)->toBeEmpty();
    }
});

test('a transport that gets no response becomes a SnelstartException with status 0', function () {
    $cause = new RuntimeException('cURL error 28: timed out with '.SUBSCRIPTION_KEY);
    $transport = (new FakeTransport)->respond('relaties', $cause);

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->status())->toBe(0)
            ->and($e->getPrevious())->toBe($cause)
            ->and($e->getMessage())->toBe('Snelstart API call failed. No response: cURL error 28: timed out with [redacted]');
    }
});

test('data that is not valid JSON is refused before anything is sent', function () {
    $transport = new FakeTransport;

    expect(fn () => client($transport)->post('relaties', ['naam' => "\xB1\x31"]))
        ->toThrow(SnelstartException::class, 'Snelstart request data cannot be sent as JSON')
        ->and($transport->requests)->toBeEmpty();
});

test('echo calls the Echo API with the subscription key only, and fetches no token', function () {
    $transport = (new FakeTransport)->respond('/echo/', ['param1' => 'sample']);

    expect(client($transport, '')->echo())->toBe(['param1' => 'sample'])
        ->and($transport->requests)->toHaveCount(1)
        ->and($transport->requests[0]['url'])->toBe('https://b2bapi.snelstart.nl/echo/resource?param1=sample')
        ->and($transport->requests[0]['headers'])->toBe([
            'Accept' => 'application/json',
            'Ocp-Apim-Subscription-Key' => SUBSCRIPTION_KEY,
        ]);
});

test('forgetToken makes the next call fetch a new token', function () {
    $transport = (new FakeTransport)->respond('b2bapi', []);
    $client = client($transport);

    $client->get('relaties');
    $client->forgetToken();
    $client->get('relaties');

    expect($transport->sent('/token'))->toHaveCount(2);
});

test('every request gets the timeouts of the config', function () {
    $transport = new class implements Transport
    {
        /** @var list<array{float, float}> */
        public array $timeouts = [];

        public function send(string $method, string $url, array $headers, ?string $body, float $timeout, float $connectTimeout): Response
        {
            $this->timeouts[] = [$timeout, $connectTimeout];

            return new Response(200, '{"access_token":"t","expires_in":3600}');
        }
    };

    $client = new Client(new Config(CLIENT_KEY, SUBSCRIPTION_KEY, timeout: 2.5, connectTimeout: 0.5), $transport);
    $client->get('relaties');
    $client->echo();

    expect($transport->timeouts)->toBe([[2.5, 0.5], [2.5, 0.5], [2.5, 0.5]]);
});

test('a key is redacted in every form a server can write it back', function (string $echoed) {
    $transport = (new FakeTransport)->respond('/token', new Response(400, 'invalid clientkey '.$echoed));

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        expect($e->getMessage())->toBe('Failed to retrieve access_token from Snelstart. HTTP status: 400. Response: invalid clientkey [redacted]');
    }
})->with([
    'as it is' => [CLIENT_KEY],
    'JSON as PHP writes it' => ['test-client-key\/with+base64=='],
    'JSON as .NET writes it' => ['test-client-key/with+base64=='],
    'JSON as .NET writes it, lowercase' => ['test-client-key/with+base64=='],
    'form encoded' => ['test-client-key%2Fwith%2Bbase64%3D%3D'],
    'form encoded, lowercase' => ['test-client-key%2fwith%2bbase64%3d%3d'],
    'HTML' => ['test-client-key/with&#x2B;base64=='],
    'HTML, decimal' => ['test-client-key/with&#43;base64=='],
]);

test('the keys are not in the stack trace of a failure', function () {
    $transport = (new FakeTransport)->respond('/token', new RuntimeException('connection refused'));

    try {
        client($transport)->get('relaties');
        $this->fail('No exception.');
    } catch (SnelstartException $e) {
        $traces = json_encode([$e->getTrace(), $e->getPrevious()?->getTrace()], JSON_PARTIAL_OUTPUT_ON_ERROR);

        expect($traces)->not->toContain('test-client-key')->not->toContain(SUBSCRIPTION_KEY);
    }
});
