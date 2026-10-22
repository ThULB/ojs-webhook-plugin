# ojs-webhook-plugin

Generic plugin for OJS 3.5 (plugin folder: `webhook`) that sends an HTTP
webhook with the article's metadata to a configurable URL whenever a
submission is sent to production or an article is published for the first
time.

## How it works

1. The plugin registers six listeners, each sending a different `event`:

   | Listener                | Fires when                                                        | `event`        |
   |-------------------------|---------------------------------------------------------------------|----------------|
   | `DecisionAdded`         | A submission receives the editorial decision `Decision::SEND_TO_PRODUCTION` ("Send to Production") | `"production"` |
   | `PublicationPublished`  | A publication transitions from *not published* to *published* (`oldPublication.status !== PUBLISHED && publication.status === PUBLISHED`). Re-saving an already-published article does **not** trigger another webhook. | `"published"`  |
   | `MetadataChanged`       | A submission's metadata is saved, **and** the Zeitschriftenserver has accepted this publication at least once before (a `zsId` is on record for it - not `doiId`, since the Zeitschriftenserver may have accepted the submission without being able to mint a DOI yet). Skipped if a `"published"` webhook for the same publication was already dispatched earlier in the same request, since OJS fires `MetadataChanged` again right after publishing. | `"updated"`    |
   | `Galley::add` / `Galley::edit` | A galley is added or edited directly on the galley ("Fahnen") form on an **already-published** publication with a `zsId` on record. Unlike the upload wizard's `saveMetadata()` action, editing a galley this way does not fire `MetadataChanged` on its own (see "Known limitations"), so a corrected galley/PDF would otherwise never reach the Zeitschriftenserver. | `"updated"`    |
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
   (required - MyCoRe-based endpoints reject requests without one; carries
   the plugin's actual installed version, read via `Plugin::getCurrentVersion()`
   at dispatch time rather than a hardcoded string) and, if configured, an
   `X-Webhook-Signature` header carrying
   `sha256=<hmac-sha256 of the request body, keyed with webhookSecret>` (the
   secret itself is never sent). Every localized field is resolved against
   `submission.locale` (the submission's own primary language), never the
   interface language of whoever triggered the webhook:

   | Field                  | Source                                              |
   |------------------------|------------------------------------------------------|
   | `event`                | `"production"`, `"published"`, `"updated"` or `"unpublished"` |
   | `source.ojsBaseUrl`    | `Config::getVar('general', 'base_url')` (stable across requests/hosts, unlike `Request::getBaseUrl()`) |
   | `source.journalPath`   | `Context::getPath()`                                 |
   | `source.contextId`     | `Submission::getData('contextId')`                   |
   | `submissionId`         | -                                                     |
   | `publicationId`        | -                                                     |
   | `version`              | `Publication::getData('version')`                    |
   | `url`                  | Article view URL                                      |
   | `locale`               | `Submission::getData('locale')`                      |
   | `title` / `prefix` / `subtitle` | `Publication::getData(…, $locale)`, read separately (unlike `getLocalizedTitle()`, `title` never has the prefix merged in). `title` and `subtitle` may contain HTML, exactly as OJS stores them (see `abstract` below). |
   | `abstract`             | `Publication::getData('abstract', $locale)` - HTML, exactly as entered in OJS's rich text editor (the receiving end is expected to convert it to plain text itself; this plugin does not strip or sanitize it) |
   | `keywords`             | `Publication::getData('keywords', $locale)`, reduced from `[{"name": ...}]` to a plain list of strings |
   | `pages`                | `Publication::getData('pages')`                      |
   | `datePublished`        | `Publication::getData('datePublished')` (`Y-m-d`)    |
   | `license.url` / `.copyrightHolder` / `.copyrightYear` | `Publication::getData('licenseUrl'\|'copyrightHolder'\|'copyrightYear')` |
   | `section.id` / `.title`| `Section::getId()` / `getTitle($locale)`             |
   | `issue.id` / `.volume` / `.number` / `.year` / `.title` / `.datePublished` | `Issue` accessors                |
   | `doi`                  | The DOI already on file in OJS for this publication (`Publication::getData('doiId')` → `Doi::getDoi()`), if any |
   | `authors`              | One entry per contributor (see below)                 |
   | `galleys`              | `null` unless the publication's own status is `STATUS_PUBLISHED` *and* the event is `published`/`updated`; one entry per galley otherwise. A publication can receive an `updated` webhook while still in production (e.g. a metadata edit after a `production` send) - at that point no galley has a public URL or a registered DOI yet, so `galleys` stays `null` even for `updated`. |

   Each entry in `authors` carries:

   | Field          | Source                                                        |
   |----------------|----------------------------------------------------------------|
   | `seq`          | `Author::getSequence()`                                        |
   | `role`         | The author's user group name, in `locale` - display fallback only, **not** meant to be parsed/guessed by the receiving end (OJS has no dedicated author/translator field, and custom groups sharing the same `roleId` are indistinguishable by name alone in every language) |
   | `userGroupId`  | `Author::getUserGroupId()` - stable, identifies the exact group  |
   | `roleId`       | `UserGroup::roleId`, one of PKP's `Role::ROLE_ID_*` constants - **does not** distinguish between groups that share a role (e.g. a custom "Translator" group is still `ROLE_ID_AUTHOR`) |
   | `roleName.en`  | `UserGroup::getLocalizedData('name', 'en_US', strict)` - `null` unless the journal has the `en_US` locale installed, since a custom group only has a translation for the locales an admin filled in (see "Known limitations") |
   | `givenName` / `familyName` | `Author::getGivenName()` / `getFamilyName()`        |
   | `orcid` / `orcidVerified` | `Author::getData('orcid'\|'orcidIsVerified')`        |
   | `affiliations` | `name` + `ror`, from OJS 3.5's structured affiliation/ROR model |

   Each entry in `galleys` carries `id`, `label`, `mimeType`, `fileId` and
   `url` (the article download URL).
4. If the response body contains a `doi` field, the job records it on the
   publication (`Repo::doi()->add()` + `doiId`) unless a *different* DOI is
   already set there, in which case the mismatch is logged instead of
   silently overwritten.
5. `SendWebhookJob` retries up to 5 times (30s / 2min / 10min / 30min
   backoff) on a network error or a `5xx` response, since the
   Zeitschriftenserver endpoint is idempotent per `submissionId`/
   `publicationId`, tolerates repeated requests, and returns `5xx` precisely
   when it cannot yet say whether the article already exists. Any other
   non-2xx response (`4xx`) is treated as permanent - the request fails
   immediately instead of retrying the same rejected request five times.
6. Every attempt's outcome is recorded in a dedicated `webhook_publication_status`
   table, one row per publication (`WebhookStatus`): the event, whether it
   succeeded, the Zeitschriftenserver's own `id`/`url` and any `warnings` from
   a successful response, or the error message once `$tries` is exhausted
   (`SendWebhookJob::failed()`). A failure leaves the last successful
   response's `id`/`url`/`warnings` in place rather than clearing them.
7. Each warning's `code` (e.g. `UNLINKED_AUTHORS`, `DOI_MISMATCH`) is translated
   to a locale string for display (`WebhookStatusFormatter::localeKeyForCode()`
   plus the `plugins.generic.webhook.warning.*` keys), shown together with the
   response's own `detail` text (English, from the Zeitschriftenserver - not
   `message`). A code added by a newer Zeitschriftenserver than this plugin
   knows about falls back to showing the raw code.
8. This status is shown read-only on the Publication tab's "Identifiers"
   section (`Form::config::before` on the `publicationIdentifiers` form,
   the same extension point the DOI/URN plugins use) and can be resent from
   the plugin grid (see "Resending a webhook" below).

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

### Recommended: enable ORCID for the journal

Independently of this plugin's own settings, the journal's ORCID integration
(Website Settings > Distribution > ORCID, enabled per journal) should be
turned on. This is journal configuration, not something this plugin checks
or warns about. Without it, authors have no way to authenticate their ORCID
iD, which has two effects on the Zeitschriftenserver side:

- Every author shows up as `orcidVerified: false`, so the `UNVERIFIED_ORCID`
  warning fires for authors who otherwise have a perfectly valid iD on file.
- An unverified ORCID iD only links to an *existing* person in the
  Zeitschriftenserver if the name matches - an author entering someone
  else's iD by mistake, or a legitimate name change, can silently link the
  wrong person (or fail to link at all) without that authentication step.

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

## Resending a webhook

The gear-icon area in the plugin grid also has a "Resend webhook" action.
Enter a submission ID (as shown in its URL) to re-dispatch the webhook for
that submission's current publication, using whatever `event` was last sent
for it (or `"updated"` if it was never sent before). Useful after a
permanently failed delivery, or after fixing the configured webhook URL.
This action is shown regardless of whether a valid webhook URL is currently
configured; if none is, nothing is queued and an error notification says so
rather than falsely reporting success.

## Requirements

- OJS 3.5.
- A running job queue worker (e.g. `php tools/jobs.php process` or the
  scheduler set up in OJS 3.5), since the webhook is sent asynchronously via
  the queue. Without a running worker, jobs stay queued and are never sent.
- `WebhookPlugin::getInstallMigration()` creates the `webhook_publication_status`
  table, but that only runs automatically on a brand-new OJS installation.
  On an *existing* installation, re-run
  `php tools/installPluginVersion.php plugins/generic/webhook/version.xml`
  after updating the plugin's files to create/update it (idempotent - safe
  to run again on a later update that doesn't change the schema).

## Known limitations / open items

- **No SSRF protection:** the target URL is not checked against private or
  internal addresses. This is intentional for local development
  (`localhost`, `127.0.0.1` are accepted on purpose), but it widens the
  attack surface in production if a journal manager can enter arbitrary
  URLs.
- **A replaced file without a galley/metadata change is not caught:** OJS's
  core `SubmissionFile` repository fires hooks for `add`/`delete` but not for
  `edit` - there is no `SubmissionFile::edit` hook to listen on. Replacing a
  galley's file through means that don't also go through `Galley::edit` (e.g.
  a bare file swap wired up by a custom workflow) will not trigger an
  `"updated"` webhook. Covered: edits made via the galley ("Fahnen") form,
  which does call `Repo::galley()->edit()`.
- **No GND field for authors:** OJS has no built-in GND identifier field, so
  it cannot be included in the `authors` payload. Decided not to add one -
  the `orcid`/`orcidVerified` fields already on each author are considered
  a sufficient identifier.
- **`roleName.en` depends on the journal's installed locales:** it is only
  populated if `en_US` is one of the journal's enabled locales (Website
  Settings > Languages), since a custom user group (e.g. "Translator") has no
  translation beyond whatever locales an admin actually filled in for it -
  there is no locale-independent fallback for those. The receiving end should
  key off `userGroupId`/`roleId` rather than depend on this field being
  present.

## Development

`WebhookUrlValidator` (the http(s)-only URL check shared by `WebhookPlugin`'s
enable-gate and `WebhookSettingsForm`'s field validator), `WebhookPayload`
(date formatting, keyword reduction and HMAC signature computation used by
`SendWebhookJob`) and `WebhookStatusFormatter` (warning-code formatting used
by `WebhookPlugin::formatStatusHtml()`) have no OJS/PKP dependency and are
covered by a PHPUnit suite that runs standalone, without an OJS installation:

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
├── WebhookPlugin.php           Plugin class, event listeners, enable gate, status display
├── WebhookSettingsForm.php     Settings form (webhook URL/secret, DOI publish gate)
├── WebhookResendForm.php       Manual "resend webhook for submission ID" form
├── WebhookStatus.php           Reads/writes the per-publication status row
├── WebhookStatusMigration.php  Creates the webhook_publication_status table
├── WebhookUrlValidator.php     Shared http(s) URL validation
├── WebhookPayload.php          Pure payload helpers (date format, keywords, HMAC signature)
├── WebhookStatusFormatter.php  Pure warning-code formatting for the status display
├── SendWebhookJob.php          Async queue job for the HTTP POST
├── settings.xml                Install defaults (enabled = false)
├── version.xml                 Plugin version information
├── templates/settings.tpl      Smarty template for the settings form
├── templates/resend.tpl        Smarty template for the resend form
├── locale/{de,en}/locale.po    Translations
└── tests/                      PHPUnit tests (WebhookUrlValidator, WebhookPayload, WebhookStatusFormatter; see Development)
```
