# bifrost-orbit/careers-sdk

The official PHP SDK for Careers Orbit. It reads live jobs, filters, the career site and a sitemap from the delivery API, sends applications with their CV and files, signs people up for the talent pool, and runs the candidate portal: sign-in with an emailed code, applications, documents, offers, the talent pool, a data download and requests for a correction or erasure. It also checks the website webhook, so you can refresh cached pages when jobs change.

It works with any PHP website, including Laravel, Symfony, WordPress and plain PHP. It is open source under the MIT licence.

Full documentation: [docs.bifrostorbit.com/careers/developers/careers-sdk](https://docs.bifrostorbit.com/careers/developers/careers-sdk/).

## Install

```bash
composer require bifrost-orbit/careers-sdk
```

It needs PHP 8.1 or later and `ext-json`. Requests go through `ext-curl` when it is installed and PHP streams when it is not, so there is nothing else to install. To use your own HTTP client, pass any PSR-18 client.

## Quick start

Create a public key under **API keys** in Careers Orbit. It starts with `careers_pk_`, and it can only read live jobs and send applications.

```bash
# .env
CAREERS_API_URL=https://api.bifrostorbit.com/careers/v1
CAREERS_PUBLIC_KEY=careers_pk_...
```

```php
use BifrostOrbit\Careers\Client;

$careers = new Client(
    publicKey: getenv('CAREERS_PUBLIC_KEY'),
    apiUrl: getenv('CAREERS_API_URL'),
    locale: 'de',
);

// or, reading CAREERS_API_URL, CAREERS_PUBLIC_KEY and CAREERS_LOCALE:
$careers = Client::fromEnvironment();

$list = $careers->getJobs(['q' => 'guide']);
foreach ($list['items'] as $job) {
    echo $job['title'], ' ', $job['url'], "\n";
}
```

| Option | What it does |
| --- | --- |
| `publicKey` | A public key (`careers_pk_…`). Required. |
| `apiUrl` | The API address. `https://api.bifrostorbit.com/careers/v1` by default; `https://api-stage.bifrostorbit.com/careers/v1` for staging. |
| `locale` | Used when a call names no language, such as `de`. Without it, the career site's main language answers. |
| `timeout` | Per attempt, in milliseconds. 5000 by default. |
| `retries` | Extra attempts at a read after a network error or a server error. 2 by default. Applications and sign-ups are sent once. |
| `httpClient` | A `Transport`, or any PSR-18 client with `requestFactory` and `streamFactory`. |

`Client::create([...])` takes the same options as an array. An unknown option is refused, so a typo never goes unnoticed.

## Methods

Every method answers with plain associative arrays, in the same shapes as the [delivery API](https://docs.bifrostorbit.com/careers/developers/delivery-api/) and the JavaScript SDK. The PHPDoc on each method describes the shape.

| Method | Answers with |
| --- | --- |
| `getJobs([department, location, remote, type, q, page, perPage, locale])` | Live jobs, newest first: `items`, `locale`, `total`, `page`, `perPage` |
| `getJob($slug, [locale])` | One job with its description, form, privacy notice and JobPosting data. Throws `NotFoundException` when it is closed or gone. |
| `findJob($slug, [locale])` | The same, or `null` |
| `getFilters([locale])` | Departments, locations, job types and workplaces that have live jobs, with counts |
| `getSite([locale])` | The career site's name, introduction, logo, colours, languages and current privacy notice |
| `getSitemap()` | Every live job with its address in each language |
| `apply($slug, $application, [cv, questions])` | Sends an application: `applicationId` and `updated` |
| `joinTalentPool($signup)` | Keeps someone's details for future jobs |
| `trackJob($slug, 'view'\|'start', [source, utmSource])` | Counts a view or an apply start for the reports. Never throws. |
| `applicationUrl($slug)` | Where applications for the job are sent |

Text that isn't translated falls back to the main language field by field. `$job['fallback']` lists those fields, and `$job['locales']` the languages the job has its own title in, for `hreflang` links.

## Applications

```php
use BifrostOrbit\Careers\Upload;

$job = $careers->getJob('mountain-guide-lofoten');

$result = $careers->apply('mountain-guide-lofoten', [
    'firstName' => 'Kari',
    'lastName' => 'Nordmann',
    'email' => 'kari@northwilds.com',
    'phone' => '+47 912 34 567',
    'answers' => ['q-years' => 6, 'q-areas' => ['lofoten', 'lyngen'], 'q-first-aid' => 'yes'],
    'privacyVersion' => $job['privacy']['version'],
    'privacyAccepted' => true,
    'talentPool' => true,
    'source' => 'northwilds.com',
    'utm' => ['source' => $_GET['utm_source'] ?? null],
    'referral' => $_GET['ref'] ?? null,
], [
    'cv' => Upload::fromPath($_FILES['cv']['tmp_name'], $_FILES['cv']['name']),
    'questions' => ['q-certificate' => Upload::fromPath($_FILES['certificate']['tmp_name'], $_FILES['certificate']['name'])],
]);
```

- Send the standard fields `$job['form']['fields']` asks for, and `answers` keyed by question id. Choice answers are option ids; yes or no answers are `'yes'` or `'no'`.
- `privacyAccepted` must be `true` and `privacyVersion` the version the candidate read. If the notice changed since, the call fails with a request to reload.
- Files are PDF or Word documents up to 10 MB. `new Upload($name, $contents, $type)` works for files you already hold in memory.
- A second application to the same job from the same email updates the first, and answers `'updated' => true`.

## Candidate portal

`PortalClient` lets candidates follow their applications on your own website. Keep the session token in something that lasts between requests, such as the PHP session, by implementing `Portal\SessionStore`:

```php
use BifrostOrbit\Careers\Portal\SessionStore;
use BifrostOrbit\Careers\PortalClient;

final class PhpSession implements SessionStore
{
    public function get(): ?string { return $_SESSION['careers_portal'] ?? null; }
    public function set(?string $token): void { $_SESSION['careers_portal'] = $token; }
}

$portal = new PortalClient(publicKey: getenv('CAREERS_PUBLIC_KEY'), locale: 'de', session: new PhpSession());

$portal->requestCode('solveig@northwilds.com');
$portal->signIn('solveig@northwilds.com', '123456');
$me = $portal->me(); // null when signed out
```

| Method | What it does |
| --- | --- |
| `requestCode($email)` | Emails a six-digit code if the address has applied |
| `signIn($email, $code)` | Keeps the session token, which lasts two hours, and answers with it |
| `me()` | Applications, offers, talent pool and requests, or `null` when signed out |
| `withdraw($applicationId, $reason)` | Withdraws an open application |
| `addDocument($applicationId, Upload $file)` | Adds a PDF or Word document |
| `openOffers()` | Notes that the candidate has seen their open offers |
| `acceptOffer($offerId, ['name' => …, 'consent' => true, 'documentHash' => …])` | Accepts an offer with a simple electronic signature |
| `declineOffer($offerId, $reason)` | Declines an offer |
| `offerPdf($offerId)` | The offer as a PDF, signed once accepted |
| `exportData()` | The candidate's data as JSON |
| `askToCorrect($detail)`, `askToErase()` | Opens a data request |
| `stayInTalentPool()`, `leaveTalentPool()` | Renews or withdraws talent pool consent |
| `stopTexts('sms'\|'whatsapp')` | Stops text messages on one channel |
| `signOut()` | Ends the session |

Calls that change something answer with the whole portal again. A `401` throws `SignedOutException` and clears the session store. Leave out `publicKey` when `apiUrl` is your own server, which adds the key.

## Refresh on job changes

```php
use BifrostOrbit\Careers\Webhook;

$body = file_get_contents('php://input');
if (!Webhook::verify(getenv('CAREERS_WEBHOOK_SECRET'), $_SERVER['HTTP_X_ORBIT_TIMESTAMP'] ?? '', $body, $_SERVER['HTTP_X_ORBIT_SIGNATURE'] ?? '')) {
    http_response_code(401);
    exit;
}
$message = json_decode($body, true);
foreach (Webhook::tags($message['event'] ?? '', $message['data'] ?? []) as $tag) {
    $cache->invalidateTags([$tag]); // careers, careers:jobs or careers:job:<slug>
}
```

## Errors

Failed calls throw `BifrostOrbit\Careers\Exception\CareersException`, or one of its kinds. Each has the HTTP `status` and the API's `errorCode`, and messages candidates can read in the language of the request.

| Exception | When |
| --- | --- |
| `NotFoundException` | No such job, or it is closed, scheduled or unpublished (404) |
| `AuthException` | No key, or a key that is wrong, revoked or expired (401) |
| `SignedOutException` | The candidate's portal session is missing or has expired (401) |
| `RateLimitException` | Too many requests. `retryAfter` is the number of seconds to wait. (429) |
| `UnavailableException` | Careers Orbit could not be reached, did not answer in time, or failed on its side, after every retry |
| `CareersException` | Anything else, such as a missing field (`422 invalid`) or a website not on the allowed list (403) |

## Tests

`BifrostOrbit\Careers\Testing\FakeTransport` answers from a queue and records each request, so your tests never call Careers Orbit:

```php
use BifrostOrbit\Careers\Client;
use BifrostOrbit\Careers\Testing\FakeTransport;

$transport = (new FakeTransport())->json(['data' => [], 'meta' => ['total' => 0]]);
$careers = new Client(publicKey: 'careers_pk_test', httpClient: $transport);

$careers->getJobs(['remote' => 'hybrid']);
$transport->lastRequest()->url; // https://api.bifrostorbit.com/careers/v1/jobs?remote=hybrid
```

## Developing the SDK

```bash
composer install
composer test
```
