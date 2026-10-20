# ojs-webhook-plugin

Generic plugin for OJS 3.5 (plugin folder: `webhook`) that sends an HTTP
webhook with the article's metadata to a configurable URL whenever a
submission is sent to production or an article is published for the first
time.

## How it works

1. The plugin registers two listeners:
   - `DecisionAdded`, fired when a submission receives the editorial decision
     `Decision::SEND_TO_PRODUCTION` ("Send to Production"). Sends `event:
     "production"`.
   - `PublicationPublished`, fired when a publication transitions from *not
     published* to *published*
     (`oldPublication.status !== PUBLISHED && publication.status ===
     PUBLISHED`). Re-saving an already-published article does **not**
     trigger another webhook. Sends `event: "published"`.
2. If a valid `webhookUrl` is configured, a `SendWebhookJob` is queued on the
   OJS job queue for the matching event. The actual HTTP request therefore
   runs asynchronously and does not block the editorial action in the
   backend.
3. The job loads the submission, publication, issue, section and journal and
   sends the following JSON payload via `POST`, with a `User-Agent` header
   (required - MyCoRe-based endpoints reject requests without one) and,
   if configured, an `X-Webhook-Secret` header carrying the `webhookSecret`
   unchanged:

   | Field                  | Source                                              |
   |------------------------|------------------------------------------------------|
   | `event`                | `"production"` or `"published"`                      |
   | `source.ojsBaseUrl`    | `Request::getBaseUrl()`                              |
   | `source.journalPath`   | `Context::getPath()`                                 |
   | `source.contextId`     | `Submission::getData('contextId')`                   |
   | `submissionId`         | -                                                     |
   | `publicationId`        | -                                                     |
   | `version`              | `Publication::getData('version')`                    |
   | `url`                  | Article view URL                                      |
   | `authors`              | One entry per contributor (see below)                 |
   | `article_title`        | `Publication::getLocalizedTitle()`                   |
   | `published_date`       | `Issue::getDatePublished()` (`Y-m-d`)                |
   | `issue_title`          | `Issue::getLocalizedTitle()`                         |
   | `abstract`             | `Publication::getLocalizedData('abstract')`          |
   | `rich_pages`           | `Section::getLocalizedTitle()`                       |
   | `keywords`             | `Publication::getLocalizedData('keywords')`          |
   | `legal`                | `Publication::getData('rights')`                     |
   | `published_institute`  | `Journal::getData('publisherInstitution')`           |
   | `issue_volume`         | `Issue::getVolume()`                                 |
   | `issue_number`         | `Issue::getNumber()`                                 |
   | `issue_year`           | `Issue::getYear()`                                   |
   | `galleys`              | `null` for `production` (no PDF with a DOI exists yet); one entry per galley for `published` |

   Each entry in `authors` carries `seq`, `role` (the author's localized user
   group name - OJS has no dedicated author/translator field), `givenName`,
   `familyName`, `orcid`, `orcidVerified` (`Author::getData('orcidIsVerified')`),
   and `affiliations` (`name` + `ror`, from OJS 3.5's structured
   affiliation/ROR model).

   Each entry in `galleys` carries `id`, `label`, `mimeType`, `fileId` and
   `url` (the article download URL).
4. If the response body contains a `doi` field, the job records it on the
   publication (`Repo::doi()->add()` + `doiId`) unless a *different* DOI is
   already set there, in which case the mismatch is logged instead of
   silently overwritten.

## Settings

Accessible from the plugin grid (`Website > Plugins > Generic Plugins`) via
the gear icon, regardless of whether the plugin is currently enabled.
Settings are stored per journal (context).

| Field            | Required | Description                                                                 |
|------------------|----------|-------------------------------------------------------------------------------|
| Webhook URL      | no*      | Target URL for the `POST` request. `http://localhost:<port>/...` is accepted for local development. May be left empty to reset the configuration (see below). |
| Webhook Secret   | no       | Sent as the `X-Webhook-Secret` header.                                        |

\* The field can be saved empty (e.g. to clear a previously configured URL),
but a syntactically valid URL must be present before the plugin can be
enabled.

Saving the form reloads the page so the plugin grid immediately reflects the
updated configuration (see below).

## Enabling the plugin

- Freshly installed, the plugin is **disabled** (`settings.xml`).
- It can only be enabled once a syntactically valid `webhookUrl` has been
  saved (`WebhookPlugin::getCanEnable()`). As long as no valid URL is
  configured, the "Enable" toggle in the plugin grid is greyed out and the
  plugin description shows a corresponding hint.
- Order of operations: open the gear icon → enter and save the webhook URL →
  enable the plugin in the grid.
- Clearing the webhook URL again (saving an empty value) resets the
  configuration. It does not automatically disable an already-enabled
  plugin, but the "Enable" toggle becomes unavailable again until a valid
  URL is configured.

## Requirements

- OJS 3.5.
- A running job queue worker (e.g. `php tools/jobs.php process` or the
  scheduler set up in OJS 3.5), since the webhook is sent asynchronously via
  the queue. Without a running worker, jobs stay queued and are never sent.

## Known limitations / open items

- **No SSRF protection:** the target URL is not checked against private or
  internal addresses. This is intentional for local development
  (`localhost`, `127.0.0.1` are accepted on purpose), but it widens the
  attack surface in production if a journal manager can enter arbitrary
  URLs.
- **No HMAC signing:** `webhookSecret` is sent as a plain-text header rather
  than being used to sign the payload, so it offers no protection against
  payload tampering.
- **No retry/backoff configuration:** `SendWebhookJob` relies on `BaseJob`'s
  defaults. If the endpoint is permanently unreachable, there is no
  notification to the journal manager, only an entry in the job/failure
  logs.

## Development

`WebhookUrlValidator` (the http(s)-only URL check shared by `WebhookPlugin`'s
enable-gate and `WebhookSettingsForm`'s field validator) has no OJS/PKP
dependency and is covered by a PHPUnit suite that runs standalone, without an
OJS installation:

```
composer install
vendor/bin/phpunit
```

The rest of the plugin (event registration, the settings form, the queue job)
depends on PKP/Laravel classes that only exist inside a running OJS instance
and is not covered by this suite.

## Files

```
webhook/
├── WebhookPlugin.php          Plugin class, event listener, enable gate
├── WebhookSettingsForm.php    Settings form (webhook URL/secret)
├── WebhookUrlValidator.php    Shared http(s) URL validation
├── SendWebhookJob.php         Async queue job for the HTTP POST
├── settings.xml               Install defaults (enabled = false)
├── version.xml                Plugin version information
├── templates/settings.tpl     Smarty template for the settings form
├── locale/{de,en}/locale.po   Translations
└── tests/                     PHPUnit tests (WebhookUrlValidator only, see Development)
```
