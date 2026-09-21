<?php

use Darvis\Snelstart\Standalone\SnelstartAPI;

/**
 * The standalone client uses cURL directly, so Http::fake() does not see it. These tests run it
 * against tests/Fixtures/standalone-server.php on 127.0.0.1; nothing leaves the machine.
 */
const STANDALONE_CLIENT_KEY = 'standalone-client-key/with+base64==';
const STANDALONE_SUBSCRIPTION_KEY = 'standalone-subscription-key';
const STANDALONE_ACCESS_TOKEN = 'standalone-access-token';

function standaloneState(?array $set = null): array
{
    static $state = [];

    return $state = $set ?? $state;
}

function standaloneUrl(string $path): string
{
    return 'http://127.0.0.1:'.standaloneState()['port'].$path;
}

function standaloneClient(string $token = '/token/ok', string $base = '/v2', ?string $subscriptionKey = STANDALONE_SUBSCRIPTION_KEY): SnelstartAPI
{
    return new SnelstartAPI([
        'base_url' => standaloneUrl($base),
        'token_url' => standaloneUrl($token),
        'client_key' => STANDALONE_CLIENT_KEY,
        'subscription_key' => $subscriptionKey,
    ]);
}

/**
 * @return array<int, array{method: string, target: string, headers: array<string, string>, body: string}>
 */
function standaloneRequests(): array
{
    $log = standaloneState()['log'];

    if (! is_file($log)) {
        return [];
    }

    return array_map(
        fn (string $line) => json_decode($line, true),
        file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES),
    );
}

beforeAll(function () {
    $directory = sys_get_temp_dir().'/snelstart-standalone-'.bin2hex(random_bytes(4));
    mkdir($directory);

    $process = proc_open(
        [PHP_BINARY, dirname(__DIR__).'/Fixtures/standalone-server.php', $directory.'/port', $directory.'/log'],
        [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', $directory.'/stderr', 'w']],
        $pipes,
    );

    for ($i = 0; $i < 100 && ! is_file($directory.'/port'); $i++) {
        usleep(50_000);
    }

    if (! is_file($directory.'/port')) {
        throw new RuntimeException('The test server did not start: '.@file_get_contents($directory.'/stderr'));
    }

    standaloneState([
        'directory' => $directory,
        'log' => $directory.'/log',
        'port' => (int) file_get_contents($directory.'/port'),
        'process' => $process,
    ]);
});

afterAll(function () {
    $state = standaloneState();

    proc_terminate($state['process']);
    proc_close($state['process']);

    array_map('unlink', glob($state['directory'].'/*') ?: []);
    rmdir($state['directory']);
});

beforeEach(function () {
    if (is_file(standaloneState()['log'])) {
        unlink(standaloneState()['log']);
    }
});

describe('construction', function () {
    it('needs a client key', function () {
        expect(fn () => new SnelstartAPI([]))->toThrow(
            RuntimeException::class,
            'Snelstart API config is incomplete (token_url, client_key).',
        );
    });

    it('needs a token URL that is not empty', function () {
        expect(fn () => new SnelstartAPI(['client_key' => 'key', 'token_url' => '']))->toThrow(RuntimeException::class);
    });

    it('falls back to the SnelStart URLs', function () {
        $client = new class(['client_key' => 'key']) extends SnelstartAPI
        {
            /** @return array<int, string|null> */
            public function settings(): array
            {
                return [$this->baseUrl, $this->tokenUrl, $this->subscriptionKey];
            }
        };

        expect($client->settings())->toBe(['https://b2bapi.snelstart.nl/v2', 'https://auth.snelstart.nl/b2b/token', null]);
    });

    it('reads the environment in fromEnv()', function () {
        putenv('SNELSTART_BASE_URL='.standaloneUrl('/v2/'));
        putenv('SNELSTART_TOKEN_URL='.standaloneUrl('/token/ok'));
        putenv('SNELSTART_CLIENT_KEY='.STANDALONE_CLIENT_KEY);
        putenv('SNELSTART_SUBSCRIPTION_KEY='.STANDALONE_SUBSCRIPTION_KEY);

        try {
            expect(SnelstartAPI::fromEnv()->getCompanyInfo())->toBe(['id' => 'abc']);
        } finally {
            foreach (['BASE_URL', 'TOKEN_URL', 'CLIENT_KEY', 'SUBSCRIPTION_KEY'] as $name) {
                putenv('SNELSTART_'.$name);
            }
        }

        $requests = standaloneRequests();

        expect($requests[1]['target'])->toBe('/v2/companyInfo')
            ->and($requests[1]['headers']['ocp-apim-subscription-key'])->toBe(STANDALONE_SUBSCRIPTION_KEY);
    });

    it('throws from fromEnv() when the client key is not in the environment', function () {
        putenv('SNELSTART_CLIENT_KEY');

        expect(fn () => SnelstartAPI::fromEnv())->toThrow(RuntimeException::class);
    });
});

describe('authentication', function () {
    it('posts the client key as a form, and sends the token and the subscription key as headers', function () {
        standaloneClient()->getRelaties(['$top' => 5]);

        [$token, $call] = standaloneRequests();

        expect($token['method'])->toBe('POST')
            ->and($token['target'])->toBe('/token/ok')
            ->and($token['headers']['content-type'])->toBe('application/x-www-form-urlencoded')
            ->and($token['headers']['accept'])->toBe('application/json')
            ->and($token['body'])->toBe('grant_type=clientkey&clientkey='.urlencode(STANDALONE_CLIENT_KEY))
            ->and($token['headers'])->not->toHaveKey('ocp-apim-subscription-key');

        expect($call['headers']['authorization'])->toBe('Bearer '.STANDALONE_ACCESS_TOKEN)
            ->and($call['headers']['ocp-apim-subscription-key'])->toBe(STANDALONE_SUBSCRIPTION_KEY)
            ->and($call['headers']['accept'])->toBe('application/json');

        foreach ([$token, $call] as $request) {
            expect(urldecode($request['target']))
                ->not->toContain(STANDALONE_CLIENT_KEY)
                ->not->toContain(STANDALONE_SUBSCRIPTION_KEY)
                ->not->toContain(STANDALONE_ACCESS_TOKEN);
        }
    });

    it('leaves the subscription header out without a subscription key', function () {
        standaloneClient(subscriptionKey: null)->getCompanyInfo();

        expect(standaloneRequests()[1]['headers'])->not->toHaveKey('ocp-apim-subscription-key');
    });

    it('fetches the token once per instance', function () {
        $client = standaloneClient();
        $client->getCompanyInfo();
        $client->getRelaties();

        expect(array_column(standaloneRequests(), 'target'))->toBe(['/token/ok', '/v2/companyInfo', '/v2/relaties']);
    });

    it('also keeps a token without expires_in for an hour', function () {
        $client = standaloneClient('/token/no-expiry');
        $client->getCompanyInfo();
        $client->getCompanyInfo();

        expect(standaloneRequests())->toHaveCount(3);
    });

    it('fetches a new token once the old one is within sixty seconds of expiring', function () {
        // expires_in is 30 here, so the token counts as expired the moment it arrives.
        $client = standaloneClient('/token/short');
        $client->getCompanyInfo();
        $client->getCompanyInfo();

        expect(array_column(standaloneRequests(), 'target'))
            ->toBe(['/token/short', '/v2/companyInfo', '/token/short', '/v2/companyInfo']);
    });

    it('throws when the token endpoint refuses the key, without calling the API', function () {
        expect(fn () => standaloneClient('/token/401')->getCompanyInfo())->toThrow(
            RuntimeException::class,
            'Failed to retrieve access_token from Snelstart. HTTP status: 401. Response: {"error":"invalid_grant"}',
        );

        expect(standaloneRequests())->toHaveCount(1);
    });

    it('throws when the token response is malformed or has no token', function (string $path) {
        expect(fn () => standaloneClient($path)->getCompanyInfo())->toThrow(
            RuntimeException::class,
            'Snelstart token response does not contain access_token.',
        );
    })->with(['/token/malformed', '/token/empty']);

    it('throws a RuntimeException when the token endpoint cannot be reached', function () {
        $client = new SnelstartAPI(['client_key' => 'key', 'token_url' => 'http://127.0.0.1:1/token']);

        expect(fn () => $client->getCompanyInfo())->toThrow(RuntimeException::class, 'Failed to retrieve access_token: cURL error:');
    });
});

describe('endpoints', function () {
    it('sends each method to its endpoint', function (string $method, array $arguments, string $verb, string $target, string $body) {
        $result = standaloneClient()->{$method}(...$arguments);

        $request = standaloneRequests()[1];

        expect($result)->toBe(['id' => 'abc'])
            ->and($request['method'])->toBe($verb)
            ->and(urldecode($request['target']))->toBe($target)
            ->and($request['body'])->toBe($body);
    })->with([
        'getCompanyInfo' => ['getCompanyInfo', [], 'GET', '/v2/companyInfo', ''],
        'getRelaties' => ['getRelaties', [], 'GET', '/v2/relaties', ''],
        'getRelaties with a query' => ['getRelaties', [['$top' => 10, '$skip' => 20]], 'GET', '/v2/relaties?$top=10&$skip=20', ''],
        'createRelatie' => ['createRelatie', [['naam' => 'Example B.V.']], 'POST', '/v2/relaties', '{"naam":"Example B.V."}'],
        'getArtikelen' => ['getArtikelen', [], 'GET', '/v2/artikelen', ''],
        'createVerkooporder' => ['createVerkooporder', [['regels' => [['aantal' => 2]]]], 'POST', '/v2/verkooporders', '{"regels":[{"aantal":2}]}'],
        'get without a leading slash' => ['get', ['relaties/r1'], 'GET', '/v2/relaties/r1', ''],
        'post' => ['post', ['/artikelen', ['omschrijving' => 'Widget']], 'POST', '/v2/artikelen', '{"omschrijving":"Widget"}'],
        'put' => ['put', ['/relaties/r1', ['naam' => 'New name']], 'PUT', '/v2/relaties/r1', '{"naam":"New name"}'],
        'delete' => ['delete', ['/relaties/r1'], 'DELETE', '/v2/relaties/r1', ''],
    ]);

    it('sends a HEAD request and does not wait for a body that never comes', function () {
        $start = microtime(true);

        $result = standaloneClient()->head('/echo/resource', ['param1' => 'x']);

        $request = standaloneRequests()[1];

        expect($result)->toBe([])
            ->and($request['method'])->toBe('HEAD')
            ->and($request['target'])->toBe('/v2/echo/resource?param1=x')
            ->and(microtime(true) - $start)->toBeLessThan(2.0);
    });

    it('returns an empty array for an empty body and for a body that is not a JSON object or list', function (string $base) {
        expect(standaloneClient(base: $base)->get('/companyInfo'))->toBe([]);
    })->with(['/no-content/v2', '/scalar/v2', '/malformed/v2']);
});

describe('failures', function () {
    it('throws a RuntimeException with the status and the body', function (int $status) {
        expect(fn () => standaloneClient(base: '/status/'.$status)->getRelaties())->toThrow(
            RuntimeException::class,
            'Snelstart API call failed. HTTP status: '.$status.'. Response: {"message":"Something went wrong"}',
        );
    })->with([400, 401, 403, 404, 429, 500, 503]);

    it('puts a body that is not JSON in the message as it is', function () {
        expect(fn () => standaloneClient(base: '/plain/v2')->getRelaties())->toThrow(
            RuntimeException::class,
            'Snelstart API call failed. HTTP status: 502. Response: Bad gateway',
        );
    });

    it('does not retry a failed call', function () {
        expect(fn () => standaloneClient(base: '/status/429')->getRelaties())->toThrow(RuntimeException::class);

        expect(standaloneRequests())->toHaveCount(2);
    });

    it('throws a RuntimeException when the API cannot be reached', function () {
        $client = new SnelstartAPI([
            'base_url' => 'http://127.0.0.1:1/v2',
            'token_url' => standaloneUrl('/token/ok'),
            'client_key' => 'key',
        ]);

        expect(fn () => $client->getCompanyInfo())->toThrow(RuntimeException::class, 'cURL error:');
    });
});

describe('secrets', function () {
    it('removes the client key from the message when the token endpoint echoes the form body', function () {
        try {
            standaloneClient('/token/echo')->getCompanyInfo();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('HTTP status: 400')
                ->toContain('clientkey=[redacted]')
                ->not->toContain('base64');
        }
    });

    it('removes the subscription key and the access token from the message of a failed call', function () {
        try {
            standaloneClient(base: '/echo-headers/v2')->getCompanyInfo();
            $this->fail('Expected an exception.');
        } catch (RuntimeException $e) {
            expect($e->getMessage())
                ->toContain('Bearer [redacted]')
                ->not->toContain(STANDALONE_SUBSCRIPTION_KEY)
                ->not->toContain(STANDALONE_ACCESS_TOKEN);
        }
    });
});
