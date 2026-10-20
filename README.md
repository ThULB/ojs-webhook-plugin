# ojs-webhook-plugin

Generic plugin for OJS 3.5 (plugin folder: `webhook`) that sends an HTTP
webhook with the article's metadata to a configurable URL whenever a
submission is sent to production or an article is published for the first
time.

## How it works

1. The plugin registers four listeners, each sending a different `event`:

   | Listener                | Fires when                                                        | `event`        |
   |-------------------------|---------------------------------------------------------------------|----------------|
   | `DecisionAdded`         | A submission receives the editorial decision `Decision::SEND_TO_PRODUCTION` ("Send to Production") | `"production"` |
   | `PublicationPublished`  | A publication transitions from *not published* to *published* (`oldPublication.status !== PUBLISHED && publication.status === PUBLISHED`). Re-saving an already-published article does **not** trigger another webhook. | `"published"`  |
   | `MetadataChanged`       | A submission's metadata is saved, **and** its current publication already has a `doiId` (i.e. it was sent at least once before) | `"updated"`    |
   | `PublicationUnpublished`| A publication is unpublished                                        | `"unpublished"`|

   A newly published version of an already-published submission does not
   need a dedicated trigger: it fires the existing `PublicationPublished`
   listener again, and the payload's `version` field tells the receiving end
   it supersedes the previous one.
2. If a valid `webhookUrl` is configured, a `SendWebhookJob` is queued on the
   OJS job queue for the matching event. The actual HTTP request therefore
   runs asynchronously and does not block the editorial action in the
   backend.
3. The job loads the submission, publication, issue, section and journal and
   sends the following JSON payload via `POST`, with a `User-Agent` header
   (required - MyCoRe-based endpoints reject requests without one) and,
   if configured, an `X-Webhook-Signature` header carrying
   `sha256=<hmac-sha256 of the request body, keyed with webhookSecret>` (the
   secret itself is never sent). Every localized field is resolved against
   `submission.locale` (the submission's own primary language), never the
   interface language of whoever triggered the webhook:

   | Field                  | Source                                              |
   |------------------------|------------------------------------------------------|
   | `event`                | `"production"`, `"published"`, `"updated"` or `"unpublished"` |
   | `source.ojsBaseUrl`    | `Request::getBaseUrl()`                              |
   | `source.journalPath`   | `Context::getPath()`                                 |
   | `source.contextId`     | `Submission::getData('contextId')`                   |
   | `submissionId`         | -                                                     |
   | `publicationId`        | -                                                     |
   | `version`              | `Publication::getData('version')`                    |
   | `url`                  | Article view URL                                      |
   | `locale`               | `Submission::getData('locale')`                      |
   | `title` / `prefix` / `subtitle` | `Publication::getData(…, $locale)`, read separately (unlike `getLocalizedTitle()`, `title` never has the prefix merged in) |
   | `abstract`             | `Publication::getData('abstract', $locale)`          |
   | `keywords`             | `Publication::getData('keywords', $locale)`, reduced from `[{"name": ...}]` to a plain list of strings |
   | `pages`                | `Publication::getData('pages')`                      |
   | `datePublished`        | `Publication::getData('datePublished')` (`Y-m-d`)    |
   | `license.url` / `.copyrightHolder` / `.copyrightYear` | `Publication::getData('licenseUrl'\|'copyrightHolder'\|'copyrightYear')` |
   | `section.id` / `.title`| `Section::getId()` / `getTitle($locale)`             |
   | `issue.id` / `.volume` / `.number` / `.year` / `.title` / `.datePublished` | `Issue` accessors                |
   | `doi`                  | The DOI already on file in OJS for this publication (`Publication::getData('doiId')` → `Doi::getDoi()`), if any |
   | `authors`              | One entry per contributor (see below)                 |
   | `galleys`              | `null` for `production` and `unpublished`; one entry per galley for `published`/`updated` |

   Each entry in `authors` carries `seq`, `role` (the author's user group
   name, in `locale` - OJS has no dedicated author/translator field),
   `givenName`, `familyName`, `orcid`, `orcidVerified`
   (`Author::getData('orcidIsVerified')`), and `affiliations` (`name` + `ror`,
   from OJS 3.5's structured affiliation/ROR model).

   Each entry in `galleys` carries `id`, `label`, `mimeType`, `fileId` and
   `url` (the article download URL).
4. If the response body contains a `doi` field, the job records it on the
   publication (`Repo::doi()->add()` + `doiId`) unless a *different* DOI is
   already set there, in which case the mismatch is logged instead of
   silently overwritten.
5. `SendWebhookJob` retries up to 5 times (30s / 2min / 10min / 30min
   backoff) on failure, since the Zeitschriftenserver endpoint is idempotent
   per `submissionId`/`publicationId` and tolerates repeated requests.

## Settings

Accessible from the plugin grid (`Website > Plugins > Generic Plugins`) via
the gear icon, regardless of whether the plugin is currently enabled.
Settings are stored per journal (context).

| Field                       | Required | Description                                                      |
|-----------------------------|----------|-------------------------------------------------------------------|
| Webhook URL                 | no*      | Target URL for the `POST` request. `http://localhost:<port>/...` is accepted for local development. May be left empty to reset the configuration (see below). For a Zeitschriftenserver (MyCoRe) endpoint, this includes that journal's own journal ID (e.g. `.../rsc/ojs/jportal_jpjournal_00002373`). |
| Webhook Secret              | no       | Used to sign each request (`X-Webhook-Signature` header); see "How it works" above. |
| Require DOI before publish  | no       | If checked, blocks publishing a submission until a DOI has been recorded for it (see `Publication::validatePublish` in "How it works"). |

\* The field can be saved empty (e.g. to clear a previously configured URL),
but a syntactically valid URL must be present before the plugin can be
enabled.

The plugin grid also warns in the plugin description if the current
journal's DOI settings don't match what the receiving end expects: DOIs
enabled for publications, suffix assigned manually (`customId`), and never
auto-generated by OJS (`neverCreationTime`) - otherwise the DOI returned by
the webhook response may not line up with what OJS itself would have
assigned.

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
- **No visible status/warnings:** a failed or permanently unreachable
  endpoint only shows up in the job/failure logs; there is no indication on
  the submission itself (sent/DOI/warnings/errors) or a "resend" action in
  the backend.
- **No GND field for authors:** OJS has no built-in GND identifier field, so
  it cannot be included in the `authors` payload.

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
├── WebhookSettingsForm.php    Settings form (webhook URL/secret, DOI publish gate)
├── WebhookUrlValidator.php    Shared http(s) URL validation
├── SendWebhookJob.php         Async queue job for the HTTP POST
├── settings.xml               Install defaults (enabled = false)
├── version.xml                Plugin version information
├── templates/settings.tpl     Smarty template for the settings form
├── locale/{de,en}/locale.po   Translations
└── tests/                     PHPUnit tests (WebhookUrlValidator only, see Development)
```
