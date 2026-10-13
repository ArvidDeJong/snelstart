<?php

declare(strict_types=1);

namespace Darvis\Snelstart\Testing;

use Darvis\Snelstart\Http\Response;
use Darvis\Snelstart\Http\Transport;

/**
 * A transport for tests: it answers from a list you give it and records what was sent, so a test
 * never reaches SnelStart. Laravel applications can use Http::fake() instead.
 *
 *     $transport = (new FakeTransport)->respond('relaties', [['naam' => 'Jansen']]);
 *     $client = new Client(new Config('client-key', 'subscription-key'), $transport);
 */
final class FakeTransport implements Transport
{
    public const ACCESS_TOKEN = 'fake-access-token';

    /**
     * Every request that was sent, in order.
     *
     * @var list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public array $requests = [];

    /**
     * @var list<array{needle: string, answers: non-empty-list<Response|\Throwable>}>
     */
    private array $rules = [];

    /**
     * Answer every request whose "METHOD url" contains $needle, for example 'relaties' or
     * 'POST https://b2bapi.snelstart.nl/v2/relaties'. Give several answers to return them in turn;
     * the last one repeats. An array is sent as JSON with status 200, a Throwable is thrown as a
     * transport that got no response. The rule added last is tried first.
     *
     * @param  array<mixed>|Response|\Throwable  ...$answers
     */
    public function respond(string $needle, array|Response|\Throwable ...$answers): self
    {
        $normalised = [];

        foreach ($answers === [] ? [[]] : $answers as $answer) {
            $normalised[] = is_array($answer) ? new Response(200, (string) json_encode($answer)) : $answer;
        }

        array_unshift($this->rules, ['needle' => $needle, 'answers' => $normalised]);

        return $this;
    }

    public function send(string $method, string $url, #[\SensitiveParameter] array $headers, #[\SensitiveParameter] ?string $body, float $timeout, float $connectTimeout): Response
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body];

        foreach ($this->rules as $index => $rule) {
            if (! str_contains($method.' '.$url, $rule['needle'])) {
                continue;
            }

            $answer = $rule['answers'][0];

            // Answers are given in turn; the last one stays and repeats.
            if (count($rule['answers']) > 1) {
                $this->rules[$index]['answers'] = array_slice($rule['answers'], 1);
            }

            if ($answer instanceof \Throwable) {
                throw $answer;
            }

            return $answer;
        }

        // The token endpoint answers by itself, so a test only describes the API.
        if (str_contains($url, '/token')) {
            return new Response(200, (string) json_encode(['access_token' => self::ACCESS_TOKEN, 'token_type' => 'bearer', 'expires_in' => 3600]));
        }

        throw new \RuntimeException('FakeTransport has no answer for '.$method.' '.$url);
    }

    /**
     * The requests whose "METHOD url" contains $needle.
     *
     * @return list<array{method: string, url: string, headers: array<string, string>, body: ?string}>
     */
    public function sent(string $needle = ''): array
    {
        return array_values(array_filter(
            $this->requests,
            static fn (array $request): bool => $needle === '' || str_contains($request['method'].' '.$request['url'], $needle),
        ));
    }
}
