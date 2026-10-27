<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers;

use BifrostOrbit\Careers\Exception\AuthException;
use BifrostOrbit\Careers\Exception\CareersException;
use BifrostOrbit\Careers\Exception\NotFoundException;
use BifrostOrbit\Careers\Exception\RateLimitException;
use BifrostOrbit\Careers\Exception\UnavailableException;
use BifrostOrbit\Careers\Http\CurlTransport;
use BifrostOrbit\Careers\Http\HttpRequest;
use BifrostOrbit\Careers\Http\HttpResponse;
use BifrostOrbit\Careers\Http\Psr18Transport;
use BifrostOrbit\Careers\Http\StreamTransport;
use BifrostOrbit\Careers\Http\Transport;
use BifrostOrbit\Careers\Http\TransportException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * @internal Sends requests for {@see Client} and {@see PortalClient}.
 */
final class Connection
{
    public const VERSION = '0.1.0';
    public const DEFAULT_API_URL = 'https://api.bifrostorbit.com/careers/v1';

    private const RETRY_DELAYS_MS = [250, 750, 2000];

    public readonly string $apiUrl;
    private readonly Transport $transport;
    private readonly int $retries;
    /** @var \Closure(int): void */
    private readonly \Closure $sleep;

    public function __construct(
        ?string $apiUrl,
        private readonly int $timeout,
        int $retries,
        Transport|ClientInterface|null $httpClient,
        ?RequestFactoryInterface $requestFactory,
        ?StreamFactoryInterface $streamFactory,
        ?\Closure $sleep,
    ) {
        if ($timeout < 1) {
            throw new \InvalidArgumentException('The timeout must be at least 1 millisecond');
        }
        $this->apiUrl = rtrim($apiUrl === null || $apiUrl === '' ? self::DEFAULT_API_URL : $apiUrl, '/');
        $this->retries = max(0, $retries);
        $this->sleep = $sleep ?? static function (int $ms): void {
            usleep($ms * 1000);
        };
        if ($httpClient instanceof ClientInterface) {
            if ($requestFactory === null || $streamFactory === null) {
                throw new \InvalidArgumentException('A PSR-18 httpClient needs a requestFactory and a streamFactory');
            }
            $this->transport = new Psr18Transport($httpClient, $requestFactory, $streamFactory);
        } else {
            $this->transport = $httpClient ?? (extension_loaded('curl') ? new CurlTransport() : new StreamTransport());
        }
    }

    public static function checkPublicKey(string $key): void
    {
        if (!str_starts_with($key, 'careers_pk_')) {
            throw new \InvalidArgumentException('The publicKey must be a Careers Orbit public key, starting with careers_pk_');
        }
    }

    /**
     * @param array<string, string|int|bool|null> $query
     */
    public function url(string $path, array $query = []): string
    {
        $pairs = [];
        foreach ($query as $key => $value) {
            if ($value === null || $value === false || $value === '') {
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode($value === true ? 'true' : (string) $value);
        }
        return $this->apiUrl . $path . ($pairs === [] ? '' : '?' . implode('&', $pairs));
    }

    /**
     * @param array<string, string> $headers
     */
    public function send(string $method, string $url, array $headers = [], ?string $body = null, ?callable $onUnauthorized = null): HttpResponse
    {
        $request = new HttpRequest($method, $url, ['User-Agent' => self::userAgent(), ...$headers], $body, $this->timeout);
        $attempts = $method === 'GET' ? $this->retries : 0;

        $lastError = null;
        for ($attempt = 0; $attempt <= $attempts; $attempt++) {
            if ($attempt > 0) {
                ($this->sleep)(self::RETRY_DELAYS_MS[$attempt - 1] ?? 2000);
            }
            try {
                $response = $this->transport->send($request);
            } catch (TransportException $error) {
                $lastError = new UnavailableException(
                    $error->timedOut ? "Careers Orbit did not answer within {$this->timeout}ms" : 'Careers Orbit could not be reached',
                    503,
                    $error,
                );
                continue;
            }
            if ($response->status >= 200 && $response->status < 300) {
                return $response;
            }

            $body = self::decode($response);
            $error = is_array($body) && is_array($body['error'] ?? null) ? $body['error'] : [];
            $message = is_string($error['message'] ?? null) ? $error['message'] : "Careers Orbit answered {$response->status}";
            $code = is_string($error['code'] ?? null) ? $error['code'] : null;
            if ($response->status >= 500) {
                $lastError = new UnavailableException($message, $response->status);
                continue;
            }
            if ($response->status === 401) {
                if ($onUnauthorized !== null) {
                    throw $onUnauthorized($message, $code ?? 'unauthorized');
                }
                throw new AuthException($message, $code ?? 'unauthorized');
            }
            if ($response->status === 404) {
                throw new NotFoundException($message, $code ?? 'not_found');
            }
            if ($response->status === 429) {
                $retryAfter = (int) ($response->header('retry-after') ?? 0);
                throw new RateLimitException($message, $retryAfter > 0 ? $retryAfter : 60);
            }
            throw new CareersException($response->status, $code ?? 'error', $message);
        }
        throw $lastError;
    }

    /**
     * @return array{data: mixed, meta?: array<string, mixed>}
     */
    public static function envelope(HttpResponse $response): array
    {
        $body = self::decode($response);
        if (!is_array($body) || !array_key_exists('data', $body)) {
            throw new CareersException($response->status, 'invalid_response', 'Careers Orbit answered with something other than JSON');
        }
        return $body;
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * @param array<string, string> $fields
     * @param array<string, Upload> $files
     * @return array{string, string} The body and its Content-Type
     */
    public static function multipart(array $fields, array $files): array
    {
        $boundary = '----careers' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote($name) . "\"\r\n\r\n{$value}\r\n";
        }
        foreach ($files as $name => $file) {
            $body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"" . self::quote($name) . '"; filename="' . self::quote($file->name) . "\"\r\n"
                . "Content-Type: {$file->type}\r\n\r\n{$file->contents}\r\n";
        }
        return [$body . "--{$boundary}--\r\n", "multipart/form-data; boundary={$boundary}"];
    }

    private static function quote(string $value): string
    {
        return str_replace(['"', "\r", "\n"], ['%22', '%0D', '%0A'], $value);
    }

    private static function decode(HttpResponse $response): mixed
    {
        if ($response->body === '') {
            return null;
        }
        try {
            return json_decode($response->body, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            return null;
        }
    }

    private static function userAgent(): string
    {
        return 'bifrost-orbit-careers-php/' . self::VERSION;
    }
}
