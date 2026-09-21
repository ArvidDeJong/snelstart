<?php

use Darvis\Snelstart\Services\SnelstartAPI;
use Darvis\Snelstart\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/**
 * @return array<int, Request>
 */
function sentRequests(): array
{
    return Http::recorded()->map(fn (array $pair) => $pair[0])->all();
}

/**
 * @return array<int, Request>
 */
function apiRequests(): array
{
    return array_values(array_filter(
        sentRequests(),
        fn (Request $request) => str_contains($request->url(), 'b2bapi.snelstart.nl'),
    ));
}

describe('authentication', function () {
    it('exchanges the client key for a token with a form post, and keeps the key out of the URL', function () {
        $this->fakeSnelstart(['naam' => 'Example B.V.']);

        app(SnelstartAPI::class)->getCompanyInfo();

        $token = sentRequests()[0];

        expect($token->method())->toBe('POST')
            ->and($token->url())->toBe('https://auth.snelstart.nl/b2b/token')
            ->and($token->isForm())->toBeTrue()
            ->and($token->data())->toBe(['grant_type' => 'clientkey', 'clientkey' => TestCase::CLIENT_KEY])
            ->and($token->hasHeader('Accept', 'application/json'))->toBeTrue()
            ->and($token->hasHeader('Ocp-Apim-Subscription-Key'))->toBeFalse();
    });

    it('sends the token as a bearer header and the subscription key as a header, never in the URL', function () {
        $this->fakeSnelstart();

        app(SnelstartAPI::class)->getRelaties(['$top' => 5]);

        $request = apiRequests()[0];

        expect($request->hasHeader('Authorization', 'Bearer '.TestCase::ACCESS_TOKEN))->toBeTrue()
            ->and($request->hasHeader('Ocp-Apim-Subscription-Key', TestCase::SUBSCRIPTION_KEY))->toBeTrue()
            ->and($request->hasHeader('Accept', 'application/json'))->toBeTrue();

        foreach (sentRequests() as $sent) {
            expect(urldecode($sent->url()))
                ->not->toContain(TestCase::CLIENT_KEY)
                ->not->toContain(TestCase::SUBSCRIPTION_KEY)
                ->not->toContain(TestCase::ACCESS_TOKEN);
        }
    });

    it('leaves the subscription header out when no subscription key is set', function () {
        config(['snelstart.subscription_key' => '']);
        $this->fakeSnelstart();

        app(SnelstartAPI::class)->getCompanyInfo();

        expect(apiRequests()[0]->hasHeader('Ocp-Apim-Subscription-Key'))->toBeFalse();
    });

    it('fetches the token once and reuses it for the next calls', function () {
        $this->fakeSnelstart();

        $api = app(SnelstartAPI::class);
        $api->getCompanyInfo();
        $api->getRelaties();
        $api->getArtikelen();

        Http::assertSentCount(4);
        expect(apiRequests())->toHaveCount(3);
    });

    it('fetches a new token sixty seconds before the old one expires', function () {
        $this->fakeSnelstart(expiresIn: 3600);

        $api = app(SnelstartAPI::class);
        $api->getCompanyInfo();

        $this->travel(3539)->seconds();
        $api->getCompanyInfo();
        Http::assertSentCount(3);

        $this->travel(2)->seconds();
        $api->getCompanyInfo();
        Http::assertSentCount(5);
    });

    it('assumes a lifetime of an hour when the token response has no expires_in', function () {
        Http::fake([
            'auth.snelstart.nl/*' => Http::response(['access_token' => TestCase::ACCESS_TOKEN]),
            'b2bapi.snelstart.nl/*' => Http::response([]),
        ]);

        $api = app(SnelstartAPI::class);
        $api->getCompanyInfo();

        $this->travel(3500)->seconds();
        $api->getCompanyInfo();
        Http::assertSentCount(3);

        $this->travel(100)->seconds();
        $api->getCompanyInfo();
        Http::assertSentCount(5);
    });

    it('does not fetch a new token when the API answers 401 on a cached token', function () {
        // Documented behaviour: a revoked token fails until it expires or the instance is forgotten.
        $calls = 0;
        $this->fakeSnelstart(function () use (&$calls) {
            return ++$calls === 1 ? Http::response([]) : Http::response(['message' => 'Unauthorized'], 401);
        });

        $api = app(SnelstartAPI::class);
        $api->getCompanyInfo();

        expect(fn () => $api->getCompanyInfo())->toThrow(RuntimeException::class, 'HTTP status: 401');
        Http::assertSentCount(3);
    });

    it('throws when the token endpoint refuses the key, without calling the API', function () {
        Http::fake([
            'auth.snelstart.nl/*' => Http::response(['error' => 'invalid_grant'], 401),
        ]);

        expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(
            RuntimeException::class,
            'Failed to retrieve access_token from Snelstart. HTTP status: 401. Response: {"error":"invalid_grant"}',
        );

        Http::assertSentCount(1);
    });

    it('throws when the token response is not JSON or has no access_token', function (string $body) {
        Http::fake(['auth.snelstart.nl/*' => Http::response($body)]);

        expect(fn () => app(SnelstartAPI::class)->getCompanyInfo())->toThrow(
            RuntimeException::class,
            'Snelstart token response does not contain access_token.',
        );
    })->with([
        'malformed JSON' => ['{"access_token": '],
        'an HTML page' => ['<html>Service unavailable</html>'],
        'JSON without the token' => ['{"token_type":"bearer"}'],
        'a JSON string' => ['"nope"'],
    ]);

    it('refuses to be built without a client key or a token URL', function (string $key) {
        config([$key => null]);

        expect(fn () => app(SnelstartAPI::class))->toThrow(
            RuntimeException::class,
            'Snelstart API config is incomplete (token_url, client_key).',
        );
    })->with(['snelstart.client_key', 'snelstart.token_url']);
});

describe('endpoints', function () {
    it('sends each convenience method to its endpoint', function (string $method, array $arguments, string $verb, string $url, array $body) {
        $this->fakeSnelstart(['id' => 'abc']);

        $result = app(SnelstartAPI::class)->{$method}(...$arguments);

        $request = apiRequests()[0];

        expect($result)->toBe(['id' => 'abc'])
            ->and($request->method())->toBe($verb)
            ->and(urldecode($request->url()))->toBe($url)
            ->and($request->data())->toBe($body);
    })->with([
        'getCompanyInfo' => ['getCompanyInfo', [], 'GET', 'https://b2bapi.snelstart.nl/v2/companyInfo', []],
        'getRelaties' => ['getRelaties', [], 'GET', 'https://b2bapi.snelstart.nl/v2/relaties', []],
        'getRelaties with a query' => ['getRelaties', [['$top' => '10', '$skip' => '20']], 'GET', 'https://b2bapi.snelstart.nl/v2/relaties?$top=10&$skip=20', ['$top' => '10', '$skip' => '20']],
        'createRelatie' => ['createRelatie', [['naam' => 'Example B.V.']], 'POST', 'https://b2bapi.snelstart.nl/v2/relaties', ['naam' => 'Example B.V.']],
        'getArtikelen' => ['getArtikelen', [], 'GET', 'https://b2bapi.snelstart.nl/v2/artikelen', []],
        'getArtikelen with a query' => ['getArtikelen', [['$filter' => "artikelcode eq 'A1'"]], 'GET', "https://b2bapi.snelstart.nl/v2/artikelen?\$filter=artikelcode eq 'A1'", ['$filter' => "artikelcode eq 'A1'"]],
        'createVerkooporder' => ['createVerkooporder', [['relatie' => ['id' => 'r1'], 'regels' => [['aantal' => 2]]]], 'POST', 'https://b2bapi.snelstart.nl/v2/verkooporders', ['relatie' => ['id' => 'r1'], 'regels' => [['aantal' => 2]]]],
        'get' => ['get', ['relaties/r1'], 'GET', 'https://b2bapi.snelstart.nl/v2/relaties/r1', []],
        'post' => ['post', ['/artikelen', ['omschrijving' => 'Widget']], 'POST', 'https://b2bapi.snelstart.nl/v2/artikelen', ['omschrijving' => 'Widget']],
        'put' => ['put', ['/relaties/r1', ['naam' => 'New name']], 'PUT', 'https://b2bapi.snelstart.nl/v2/relaties/r1', ['naam' => 'New name']],
        'delete' => ['delete', ['/relaties/r1'], 'DELETE', 'https://b2bapi.snelstart.nl/v2/relaties/r1', []],
        'head' => ['head', ['/echo/resource', ['param1' => 'x']], 'HEAD', 'https://b2bapi.snelstart.nl/v2/echo/resource?param1=x', ['param1' => 'x']],
    ]);

    it('sends a body as JSON', function () {
        $this->fakeSnelstart();

        app(SnelstartAPI::class)->createRelatie(['naam' => 'Example B.V.']);

        $request = apiRequests()[0];

        expect($request->isJson())->toBeTrue()
            ->and($request->body())->toBe('{"naam":"Example B.V."}');
    });

    it('uses the base URL from the config, with or without a trailing slash', function () {
        config(['snelstart.base_url' => 'https://b2bapi.snelstart.nl/v2/']);
        $this->fakeSnelstart();

        app(SnelstartAPI::class)->get('companyInfo');

        expect(apiRequests()[0]->url())->toBe('https://b2bapi.snelstart.nl/v2/companyInfo');
    });

    it('returns an empty array for an empty body', function () {
        $this->fakeSnelstart(fn () => Http::response('', 204));

        expect(app(SnelstartAPI::class)->delete('/relaties/r1'))->toBe([]);
    });

    it('returns an empty array for a body that is not a JSON object or list', function (string $body) {
        $this->fakeSnelstart(fn () => Http::response($body, 200));

        expect(app(SnelstartAPI::class)->get('/companyInfo'))->toBe([]);
    })->with([
        'malformed JSON' => ['{"naam": '],
        'an HTML page' => ['<html>Maintenance</html>'],
        'a JSON string' => ['"ok"'],
        'a JSON number' => ['42'],
        'a JSON boolean' => ['true'],
    ]);
});

describe('failures', function () {
    it('throws a RuntimeException with the status and the JSON body', function (int $status) {
        $this->fakeSnelstart(fn () => Http::response(['message' => 'Something went wrong'], $status));

        expect(fn () => app(SnelstartAPI::class)->getRelaties())->toThrow(
            RuntimeException::class,
            'Snelstart API call failed. HTTP status: '.$status.'. Response: {"message":"Something went wrong"}',
        );
    })->with([400, 401, 403, 404, 429, 500, 503]);

    it('puts a body that is not JSON in the message as it is', function () {
        $this->fakeSnelstart(fn () => Http::response('Bad gateway', 502));

        expect(fn () => app(SnelstartAPI::class)->getRelaties())->toThrow(
            RuntimeException::class,
            'Snelstart API call failed. HTTP status: 502. Response: Bad gateway',
        );
    });

    it('leaves the response part out when the error has no body', function () {
        $this->fakeSnelstart(fn () => Http::response('', 500));

        try {
            app(SnelstartAPI::class)->getRelaties();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toBe('Snelstart API call failed. HTTP status: 500.');
        }
    });

    it('does not retry a failed call', function () {
        $this->fakeSnelstart(fn () => Http::response([], 429));

        expect(fn () => app(SnelstartAPI::class)->getRelaties())->toThrow(RuntimeException::class);

        expect(apiRequests())->toHaveCount(1);
    });

    it('lets a connection error through as the ConnectionException of the HTTP client', function () {
        $this->fakeSnelstart(function () {
            throw new ConnectionException('cURL error 28: Operation timed out after 30001 milliseconds');
        });

        expect(fn () => app(SnelstartAPI::class)->getRelaties())->toThrow(ConnectionException::class, 'cURL error 28');
    });

    it('lets a connection error on the token endpoint through as well', function () {
        Http::fake(['auth.snelstart.nl/*' => function () {
            throw new ConnectionException('cURL error 6: Could not resolve host');
        }]);

        expect(fn () => app(SnelstartAPI::class)->getRelaties())->toThrow(ConnectionException::class);
    });
});

describe('secrets', function () {
    it('removes the client key from the message when the token endpoint echoes it', function () {
        Http::fake([
            'auth.snelstart.nl/*' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Unknown clientkey '.TestCase::CLIENT_KEY,
            ], 400),
        ]);

        try {
            app(SnelstartAPI::class)->getCompanyInfo();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('HTTP status: 400')
                ->toContain('Unknown clientkey [redacted]')
                ->not->toContain(TestCase::CLIENT_KEY)
                ->not->toContain(trim((string) json_encode(TestCase::CLIENT_KEY), '"'))
                ->not->toContain('base64');
        }
    });

    it('removes the client key from a form encoded echo', function () {
        Http::fake([
            'auth.snelstart.nl/*' => Http::response('Bad request: grant_type=clientkey&clientkey='.urlencode(TestCase::CLIENT_KEY), 400),
        ]);

        try {
            app(SnelstartAPI::class)->getCompanyInfo();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())->toContain('clientkey=[redacted]')->not->toContain('base64');
        }
    });

    it('removes the subscription key and the access token from the message of a failed call', function () {
        $this->fakeSnelstart(fn (Request $request) => Http::response([
            'headers' => [
                'Authorization' => $request->header('Authorization')[0],
                'Ocp-Apim-Subscription-Key' => $request->header('Ocp-Apim-Subscription-Key')[0],
            ],
        ], 500));

        try {
            app(SnelstartAPI::class)->getCompanyInfo();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('Bearer [redacted]')
                ->not->toContain(TestCase::SUBSCRIPTION_KEY)
                ->not->toContain(TestCase::ACCESS_TOKEN);
        }
    });
});
