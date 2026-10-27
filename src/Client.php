<?php

declare(strict_types=1);

namespace BifrostOrbit\Careers;

use BifrostOrbit\Careers\Exception\CareersException;
use BifrostOrbit\Careers\Exception\NotFoundException;
use BifrostOrbit\Careers\Http\Transport;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Reads jobs from the Careers Orbit delivery API and sends applications and talent pool sign-ups.
 *
 * Every method answers with the API's `data` as associative arrays, in the same shapes as the
 * JavaScript SDK.
 *
 * @phpstan-type Location array{id: string, name: string, street: string, postalCode: string, city: string, region: string, country: string, remote: 'onsite'|'hybrid'|'remote'}
 * @phpstan-type Salary array{min: int|float|null, max: int|float|null, currency: string, period: 'year'|'month'|'hour', text: string}
 * @phpstan-type JobSummary array{id: string, slug: string, url: string, title: string, summary: string, department: array{id: string, name: string}|null, locations: list<Location>, employmentType: string, employmentTypeLabel: string, remote: 'onsite'|'hybrid'|'remote', remoteLabel: string, salary: Salary|null, publishedAt: string, closesAt: string|null, internal: bool}
 * @phpstan-type Question array{id: string, kind: 'text'|'longText'|'choice'|'multiChoice'|'yesNo'|'file'|'date'|'number', label: string, help: string, required: bool, options: list<array{id: string, label: string}>}
 * @phpstan-type Privacy array{version: int, text: string}
 * @phpstan-type Job JobSummary&array{locale: string, locales: list<string>, fallback: list<string>, description: string, seo: array{title: string, description: string}, cmsEntryId: string|null, form: array{fields: array<string, 'required'|'optional'|'off'>, questions: list<Question>}, privacy: Privacy, jobPosting: array<string, mixed>}
 * @phpstan-type JobList array{items: list<JobSummary>, locale: string, total: int, page: int, perPage: int}
 * @phpstan-type Site array{name: string, slug: string, intro: string, logoUrl: string|null, colours: array{brand: string|null, accent: string|null}, locale: string, languages: list<string>, defaultLanguage: string, contactEmail: string|null, websiteUrl: string|null, hostedUrl: string, privacy: Privacy, insights?: array{site: string}|null, texting?: array{sms: bool, whatsapp: bool}|null}
 * @phpstan-type FilterOption array{value: string, label: string, count: int}
 * @phpstan-type Filters array{departments: list<FilterOption>, locations: list<FilterOption&array{city: string, country: string}>, employmentTypes: list<FilterOption>, remote: list<FilterOption>}
 * @phpstan-type SitemapEntry array{url: string, slug: string, updatedAt: string, alternates: array<string, string>}
 * @phpstan-type Application array{firstName: string, lastName: string, email: string, phone?: string|null, coverLetter?: string|null, linkedin?: string|null, location?: string|null, salaryExpectation?: string|null, startDate?: string|null, answers?: array<string, string|list<string>|int|float|bool|null>, privacyVersion: int, privacyAccepted: true, talentPool?: bool, texting?: list<'sms'|'whatsapp'>, locale?: string, source?: string, utm?: array{source?: string|null, medium?: string|null, campaign?: string|null}, referrer?: string|null, referral?: string|null}
 * @phpstan-type TalentPoolSignup array{firstName: string, lastName?: string, email: string, privacyVersion: int, privacyAccepted: true, locale?: string, jobAlerts?: bool, departmentIds?: list<string>}
 */
final class Client
{
    public const VERSION = Connection::VERSION;
    public const DEFAULT_API_URL = Connection::DEFAULT_API_URL;

    private const JOB_QUERY = ['department', 'location', 'remote', 'type', 'q', 'page', 'perPage', 'locale'];

    /** The delivery API address, such as https://api.bifrostorbit.com/careers/v1 */
    public readonly string $apiUrl;
    public readonly ?string $locale;

    private readonly string $publicKey;
    private readonly Connection $connection;

    /**
     * @param string $publicKey A public key (careers_pk_), from API keys in Careers Orbit.
     * @param string|null $apiUrl The API address. https://api.bifrostorbit.com/careers/v1 by default; use https://api-stage.bifrostorbit.com/careers/v1 for staging.
     * @param string|null $locale Used when a call names no language, such as "de". Without it, the career site's main language answers.
     * @param int $timeout Per attempt, in milliseconds.
     * @param int $retries Extra attempts at a read after a network error or a server error. Applications are sent once.
     * @param Transport|ClientInterface|null $httpClient A transport, or any PSR-18 client together with $requestFactory and $streamFactory. ext-curl, or PHP streams, by default.
     * @param (\Closure(int): void)|null $sleep Waits between retries, given milliseconds. For tests.
     */
    public function __construct(
        string $publicKey,
        ?string $apiUrl = null,
        ?string $locale = null,
        int $timeout = 5000,
        int $retries = 2,
        Transport|ClientInterface|null $httpClient = null,
        ?RequestFactoryInterface $requestFactory = null,
        ?StreamFactoryInterface $streamFactory = null,
        ?\Closure $sleep = null,
    ) {
        if ($publicKey === '') {
            throw new \InvalidArgumentException('A publicKey is required');
        }
        Connection::checkPublicKey($publicKey);
        $this->connection = new Connection($apiUrl, $timeout, $retries, $httpClient, $requestFactory, $streamFactory, $sleep);
        $this->apiUrl = $this->connection->apiUrl;
        $this->locale = $locale === '' ? null : $locale;
        $this->publicKey = $publicKey;
    }

    /**
     * Builds a client from an options array, as kept in a framework's configuration.
     *
     * @param array<string, mixed> $options The constructor's arguments by name: publicKey, apiUrl, locale, timeout, retries, httpClient, requestFactory, streamFactory
     */
    public static function create(array $options): self
    {
        self::checkOptions($options, ['publicKey', 'apiUrl', 'locale', 'timeout', 'retries', 'httpClient', 'requestFactory', 'streamFactory'], 'create');
        if (!isset($options['publicKey'])) {
            throw new \InvalidArgumentException('A publicKey is required');
        }
        return new self(...$options);
    }

    /**
     * Builds a client from CAREERS_API_URL, CAREERS_PUBLIC_KEY and CAREERS_LOCALE.
     */
    public static function fromEnvironment(): self
    {
        $read = static function (string $name): ?string {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);
            return is_string($value) && $value !== '' ? $value : null;
        };
        return new self(
            publicKey: $read('CAREERS_PUBLIC_KEY') ?? '',
            apiUrl: $read('CAREERS_API_URL'),
            locale: $read('CAREERS_LOCALE'),
        );
    }

    /**
     * Live jobs, newest first, filtered and paged.
     *
     * @param array{department?: string|null, location?: string|null, remote?: string|null, type?: string|null, q?: string|null, page?: int, perPage?: int, locale?: string|null} $query
     * @return JobList
     */
    public function getJobs(array $query = []): array
    {
        self::checkOptions($query, self::JOB_QUERY, 'getJobs');
        $locale = $query['locale'] ?? null;
        unset($query['locale']);
        $answer = $this->read('/jobs', ['locale' => $locale ?? $this->locale, ...$query]);
        $items = is_array($answer['data']) ? $answer['data'] : [];
        $meta = is_array($answer['meta'] ?? null) ? $answer['meta'] : [];
        return [
            'items' => $items,
            'locale' => (string) ($meta['locale'] ?? $locale ?? $this->locale ?? 'en'),
            'total' => (int) ($meta['total'] ?? count($items)),
            'page' => (int) ($meta['page'] ?? 1),
            'perPage' => (int) ($meta['perPage'] ?? count($items)),
        ];
    }

    /**
     * One live job with its description, form, privacy notice and JobPosting data. Throws {@see NotFoundException} when it is closed or gone.
     *
     * @param array{locale?: string|null} $options
     * @return Job
     */
    public function getJob(string $slug, array $options = []): array
    {
        self::checkOptions($options, ['locale'], 'getJob');
        return $this->read('/jobs/' . rawurlencode($slug), ['locale' => $options['locale'] ?? $this->locale])['data'];
    }

    /**
     * Like {@see getJob()}, but answers null instead of throwing when the job is closed or gone.
     *
     * @param array{locale?: string|null} $options
     * @return Job|null
     */
    public function findJob(string $slug, array $options = []): ?array
    {
        try {
            return $this->getJob($slug, $options);
        } catch (NotFoundException) {
            return null;
        }
    }

    /**
     * The career site's name, introduction, logo, colours, languages and current privacy notice.
     *
     * @param array{locale?: string|null} $options
     * @return Site
     */
    public function getSite(array $options = []): array
    {
        self::checkOptions($options, ['locale'], 'getSite');
        return $this->read('/site', ['locale' => $options['locale'] ?? $this->locale])['data'];
    }

    /**
     * Departments, locations, job types and workplaces that have live jobs, with counts.
     *
     * @param array{locale?: string|null} $options
     * @return Filters
     */
    public function getFilters(array $options = []): array
    {
        self::checkOptions($options, ['locale'], 'getFilters');
        return $this->read('/filters', ['locale' => $options['locale'] ?? $this->locale])['data'];
    }

    /**
     * Every live job with its address in each language.
     *
     * @return list<SitemapEntry>
     */
    public function getSitemap(): array
    {
        return $this->read('/sitemap', ['locale' => $this->locale])['data'];
    }

    /**
     * Where applications for a job are sent, for a form that posts straight to Careers Orbit.
     */
    public function applicationUrl(string $slug): string
    {
        return $this->connection->url('/jobs/' . rawurlencode($slug) . '/applications');
    }

    /**
     * Sends an application. A second application to the same job from the same email updates the first.
     *
     * @param Application $application
     * @param array{cv?: Upload|null, questions?: array<string, Upload>} $files The CV, and a file for each file question by question id
     * @return array{applicationId: string, updated: bool}
     */
    public function apply(string $slug, array $application, array $files = []): array
    {
        self::checkOptions($files, ['cv', 'questions'], 'apply');
        foreach (['firstName', 'lastName', 'email', 'privacyVersion'] as $required) {
            if (!isset($application[$required]) || $application[$required] === '') {
                throw new \InvalidArgumentException("An application needs {$required}");
            }
        }
        if (($application['privacyAccepted'] ?? null) !== true) {
            throw new \InvalidArgumentException('An application needs privacyAccepted => true, once the candidate has agreed to the privacy notice');
        }
        $uploads = [];
        if (($files['cv'] ?? null) instanceof Upload) {
            $uploads['cv'] = $files['cv'];
        }
        foreach ($files['questions'] ?? [] as $questionId => $file) {
            $uploads['file:' . $questionId] = $file;
        }
        [$body, $type] = Connection::multipart(['data' => Connection::json($application)], $uploads);
        $response = $this->connection->send('POST', $this->applicationUrl($slug), [...$this->headers(), 'Content-Type' => $type], $body);
        $data = Connection::envelope($response)['data'];
        return ['applicationId' => (string) ($data['applicationId'] ?? ''), 'updated' => (bool) ($data['updated'] ?? false)];
    }

    /**
     * Keeps someone's details for future jobs, and with `jobAlerts` emails them when one opens.
     *
     * @param TalentPoolSignup $signup
     */
    public function joinTalentPool(array $signup): void
    {
        if (($signup['privacyAccepted'] ?? null) !== true) {
            throw new \InvalidArgumentException('A sign-up needs privacyAccepted => true, once the person has agreed to the privacy notice');
        }
        $this->connection->send('POST', $this->connection->url('/talent-pool'), [...$this->headers(), 'Content-Type' => 'application/json'], Connection::json($signup));
    }

    /**
     * Counts a view of a job or the start of an application, for the reports' channels. Carries nothing about the person, and never throws.
     *
     * @param 'view'|'start' $kind
     * @param array{source?: string|null, utmSource?: string|null} $source
     */
    public function trackJob(string $slug, string $kind, array $source = []): void
    {
        try {
            $this->connection->send(
                'POST',
                $this->connection->url('/jobs/' . rawurlencode($slug) . '/track'),
                [...$this->headers(), 'Content-Type' => 'application/json'],
                Connection::json(['kind' => $kind, ...$source]),
            );
        } catch (CareersException) {
        }
    }

    /**
     * @param array<string, string|int|null> $query
     * @return array{data: mixed, meta?: array<string, mixed>}
     */
    private function read(string $path, array $query): array
    {
        return Connection::envelope($this->connection->send('GET', $this->connection->url($path, $query), $this->headers()));
    }

    /**
     * @return array<string, string>
     */
    private function headers(): array
    {
        return ['Authorization' => 'Bearer ' . $this->publicKey, 'Accept' => 'application/json'];
    }

    /**
     * @param array<string, mixed> $options
     * @param list<string> $known
     */
    private static function checkOptions(array $options, array $known, string $method): void
    {
        $unknown = array_diff(array_keys($options), $known);
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf('Unknown option%s for %s: %s', count($unknown) > 1 ? 's' : '', $method, implode(', ', $unknown)));
        }
    }
}
