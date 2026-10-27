# Changelog

## Unreleased

- Text messages: the career site carries `texting`, `['sms' => bool, 'whatsapp' => bool]` or `null`, an application can send `texting` with the channels the candidate agreed to, the portal lists them in `texting`, and `PortalClient::stopTexts($channel)` stops one.
- First release. `Client` reads live jobs, a job with its form and privacy notice, filters, the career site and a sitemap, sends applications with a CV and question files as `multipart/form-data`, signs people up for the talent pool and counts job views and apply starts. `PortalClient` signs candidates in with an emailed code, keeps the `X-Careers-Session` token in a `SessionStore`, and covers withdrawing, documents, offers (open, accept with a simple electronic signature, decline, PDF), the data download, data requests and the talent pool. `Webhook::verify` and `Webhook::tags` check the website webhook. Failed calls throw `CareersException` or one of its kinds.
