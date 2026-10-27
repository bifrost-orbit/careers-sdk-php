<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers;

use BifrostOrbit\Careers\Exception\CareersException;
use BifrostOrbit\Careers\Exception\SignedOutException;
use BifrostOrbit\Careers\Http\HttpResponse;
use BifrostOrbit\Careers\Http\Transport;
use BifrostOrbit\Careers\Portal\MemorySession;
use BifrostOrbit\Careers\Portal\SessionStore;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * The candidate portal: sign-in with an emailed code, applications, documents, offers, the talent pool,
 * a data download and requests for a correction or erasure.
 *
 * Calls that change something answer with the whole portal again, so a page can show it straight away.
 *
 * @phpstan-type PortalFile array{id: string, name: string, size: int, kind: string, createdAt: string}
 * @phpstan-type PortalApplication array{id: string, jobTitle: string, jobUrl: string|null, stage: string, status: 'active'|'rejected'|'withdrawn'|'hired', appliedAt: string, updatedAt: string, canWithdraw: bool, canUpload: bool, files: list<PortalFile>}
 * @phpstan-type PortalOffer array{id: string, applicationId: string, jobTitle: string, title: string, body: string, status: 'open'|'accepted'|'declined'|'expired', sentAt: string|null, expiresAt: string|null, documentHash: string|null, signedAs: string|null, answeredAt: string|null}
 * @phpstan-type Portal array{candidate: array{firstName: string, lastName: string, email: string, locale: string}, site: array{name: string, privacyVersion: int, contactEmail: string|null}, applications: list<PortalApplication>, offers?: list<PortalOffer>, texting?: list<'sms'|'whatsapp'>, talentPool: array{member: bool, until: string|null}, requests: list<array{id: string, kind: 'access'|'erasure'|'correction', status: 'open'|'done'|'declined', receivedAt: string, dueAt: string}>, erasureAt: string|null}
 */
final class PortalClient
{
    public readonly string $apiUrl;
    public readonly ?string $locale;
    public readonly SessionStore $session;

    private readonly Connection $connection;

    /**
     * @param string|null $publicKey A public key (careers_pk_). Leave it out when $apiUrl is your own server, which adds the key.
     * @param string|null $apiUrl The API address. https://api.bifrostorbit.com/careers/v1 by default.
     * @param string|null $locale The language for emails and stage names, such as "de".
     * @param SessionStore|null $session Where the candidate's session token is kept. In memory by default.
     */
    public function __construct(
        private readonly ?string $publicKey = null,
        ?string $apiUrl = null,
        ?string $locale = null,
        ?SessionStore $session = null,
        int $timeout = 5000,
        int $retries = 2,
        Transport|ClientInterface|null $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?\Closure $sleep = null,
    ) {
        if ($publicKey !== null && $publicKey !== '') {
            Connection::checkPublicKey($publicKey);
        }
        $this->connection = new Connection($apiUrl, $timeout, $retries, $httpClient, $requestFactory, $streamFactory, $sleep);
        $this->apiUrl = $this->connection->apiUrl;
        $this->locale = $locale === '' ? null : $locale;
        $this->session = $session ?? new MemorySession();
    }

    /**
     * Emails a six-digit code if the address has applied. Answers the same either way.
     */
    public function requestCode(string $email): void
    {
        $this->call('POST', 'code', ['email' => $email, 'locale' => $this->locale]);
    }

    /**
     * Checks the code and keeps the session token, which lasts two hours. Answers with the token.
     */
    public function signIn(string $email, string $code): ?string
    {
        $data = Connection::envelope($this->call('POST', 'session', ['email' => $email, 'code' => $code, 'locale' => $this->locale]))['data'];
        $token = is_array($data) && is_string($data['token'] ?? null) ? $data['token'] : null;
        $this->session->set($token);
        return $token;
    }

    public function signOut(): void
    {
        try {
            $this->call('DELETE', 'session');
        } catch (CareersException) {
        }
        $this->session->set(null);
    }

    /**
     * The candidate's applications, offers, talent pool and requests, or null when signed out.
     *
     * @return Portal|null
     */
    public function me(): ?array
    {
        try {
            $data = $this->view('GET', 'me');
        } catch (SignedOutException) {
            return null;
        }
        if ($data === null) {
            $this->session->set(null);
        }
        return $data;
    }

    /**
     * @return Portal
     */
    public function withdraw(string $applicationId, ?string $reason = null): array
    {
        return $this->view('POST', 'applications/' . rawurlencode($applicationId) . '/withdraw', ['reason' => $reason]);
    }

    /**
     * Adds a PDF or Word document, up to 10 MB, to an open application.
     *
     * @return Portal
     */
    public function addDocument(string $applicationId, Upload $file): array
    {
        [$body, $type] = Connection::multipart([], ['file' => $file]);
        $response = $this->connection->send(
            'POST',
            $this->url('applications/' . rawurlencode($applicationId) . '/documents'),
            [...$this->headers(), 'Content-Type' => $type],
            $body,
            $this->signedOut(...),
        );
        return Connection::envelope($response)['data'];
    }

    /**
     * The candidate's data as JSON, with links to their files that work for 15 minutes.
     */
    public function exportData(): string
    {
        return $this->call('GET', 'export')->body;
    }

    /**
     * @return Portal
     */
    public function askToErase(): array
    {
        return $this->view('POST', 'requests', ['kind' => 'erasure', 'detail' => null]);
    }

    /**
     * @return Portal
     */
    public function askToCorrect(string $detail): array
    {
        return $this->view('POST', 'requests', ['kind' => 'correction', 'detail' => $detail]);
    }

    /**
     * Notes that the candidate has seen their open offers.
     *
     * @return Portal
     */
    public function openOffers(): array
    {
        return $this->view('POST', 'offers');
    }

    /**
     * Accepts an offer with a simple electronic signature: the candidate's typed name, their consent and the
     * `documentHash` of the offer they read.
     *
     * @param array{name: string, consent: bool, documentHash: string} $acceptance
     * @return Portal
     */
    public function acceptOffer(string $offerId, array $acceptance): array
    {
        return $this->view('POST', 'offers/' . rawurlencode($offerId) . '/answer', ['action' => 'accept', ...$acceptance]);
    }

    /**
     * @return Portal
     */
    public function declineOffer(string $offerId, ?string $reason = null): array
    {
        return $this->view('POST', 'offers/' . rawurlencode($offerId) . '/answer', ['action' => 'decline', 'reason' => $reason]);
    }

    /**
     * The offer as a PDF, signed once it has been accepted.
     */
    public function offerPdf(string $offerId): string
    {
        return $this->call('GET', 'offers/' . rawurlencode($offerId) . '/pdf')->body;
    }

    /**
     * Stops text messages on one channel.
     *
     * @param 'sms'|'whatsapp' $channel
     * @return Portal
     */
    public function stopTexts(string $channel): array
    {
        return $this->view('POST', 'texting/stop', ['channel' => $channel]);
    }

    /**
     * @return Portal
     */
    public function leaveTalentPool(): array
    {
        return $this->view('POST', 'talent-pool/leave');
    }

    /**
     * @return Portal
     */
    public function stayInTalentPool(): array
    {
        return $this->view('POST', 'talent-pool/stay');
    }

    /**
     * @param array<string, mixed>|null $body
     * @return array<string, mixed>|null
     */
    private function view(string $method, string $path, ?array $body = null): ?array
    {
        $data = Connection::envelope($this->call($method, $path, $body))['data'];
        return is_array($data) ? $data : null;
    }

    /**
     * @param array<string, mixed>|null $body
     */
    private function call(string $method, string $path, ?array $body = null): HttpResponse
    {
        $headers = $this->headers();
        if ($body !== null) {
            $headers['Content-Type'] = 'application/json';
        } elseif ($method === 'POST') {
            $headers['Content-Type'] = 'application/json';
            $body = [];
        }
        return $this->connection->send($method, $this->url($path), $headers, $body === null ? null : Connection::json((object) $body), $this->signedOut(...));
    }

    private function signedOut(string $message, string $code): SignedOutException
    {
        $this->session->set(null);
        return new SignedOutException($message, $code);
    }

    private function url(string $path): string
    {
        return $this->connection->url('/portal/' . $path, ['locale' => $this->locale]);
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        $headers = ['Accept' => 'application/json'];
        if ($this->publicKey !== null && $this->publicKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->publicKey;
        }
        $token = $this->session->get();
        if ($token !== null && $token !== '') {
            $headers['X-Careers-Session'] = $token;
        }
        return $headers;
    }
}
