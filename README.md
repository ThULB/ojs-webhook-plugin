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
   | `Galley::add` / `Galley::edit` | A galley is added, or edited with an actual change to its `label`, `locale`, `submissionFileId` or `urlRemote` (not e.g. a drag-and-drop reorder, which only changes `seq`), on an **already-published** publication with a `zsId` on record. Unlike the upload wizard's `saveMetadata()` action, editing a galley this way does not fire `MetadataChanged` on its own (see "Known limitations"), so a corrected galley/PDF would otherwise never reach the Zeitschriftenserver. | `"updated"`    |
   | `PublicationUnpublished`| A publication is unpublished                                        | `"unpublished"`|

   A newly published version of an already-published submission does not
   need a dedicated trigger: it fires the existing `PublicationPublished`
   listener again, and the payload's `version` field tells the receiving end
   it supersedes the previous one.
2. If the plugin is enabled for the submission's journal and a valid
   `webhookUrl` is configured there, a `SendWebhookJob` is queued on the OJS
   job queue for the matching event. The actual HTTP request therefore runs
   asynchronously and does not block the editorial action in the backend.
   The listeners themselves are always registered and check the enabled flag
   per journal, so this also works for the CLI scheduler (which loads
   plugins without a journal context) - in particular for articles published
   on their scheduled date by the `PublishSubmissions` task.
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
   | `role`         | The author's user group name, in `locale` - display fallback only, **not** meant to be parsed/guessed by the receiving end (OJS has no dedicated author/translator field) |
   | `userGroupId`  | `Author::getUserGroupId()` - stable, identifies the exact group  |
   | `roleKey`      | `UserGroup::nameLocaleKey` - the locale-independent key behind a *default* user group's translated name (e.g. `default.groups.name.author`, `default.groups.name.translator`), stable across renames and across languages. `null` for a user group the editorial office created itself, which has no such key. `UserGroup::roleId` (one of PKP's `Role::ROLE_ID_*` constants) is deliberately not sent - it does not distinguish between groups that share a role (a custom "Translator" group is still `ROLE_ID_AUTHOR`, same as "Author"), so it carries no information `roleKey`/`userGroupId` doesn't already. |
   | `givenName` / `familyName` | `Author::getGivenName()` / `getFamilyName()`        |
   | `orcid` / `orcidVerified` | `Author::getData('orcid'\|'orcidIsVerified')`        |
   | `affiliations` | `name` + `ror`, from OJS 3.5's structured affiliation/ROR model |

   Each entry in `galleys` carries `id`, `label`, `mimeType`, `fileId` and
   `url` (the article download URL).
4. If the response body contains a `doi` field, the job records it on the
   publication (`Repo::doi()->add()` + `doiId`) unless a *different* DOI is
   already set there, in which case the mismatch is logged instead of
   silently overwritten. The publication is re-read right before recording
   the DOI, since `Repo::publication()->edit()` writes back every field of
   the object it is given - reusing the copy loaded before the HTTP request
   would revert any edit saved while the request was in flight.
   The response body is parsed leniently (`WebhookPayload::normalizeResponse()`):
   a body that is not a JSON object, or a `doi`/`id`/`url`/`warnings` field
   of an unexpected type, is treated as absent rather than failing the job -
   the request was already delivered, so failing would only make the queue
   re-send it.
5. `SendWebhookJob` retries up to 5 times (30s / 2min / 10min / 30min
   backoff) on a network error or a `5xx` response, since the
   Zeitschriftenserver endpoint is idempotent per `submissionId`/
   `publicationId`, tolerates repeated requests, and returns `5xx` precisely
   when it cannot yet say whether the article already exists. Any other
   non-2xx response (`4xx`) is treated as permanent - the request fails
   immediately instead of retrying the same rejected request five times.
   Before every attempt, the job checks whether its event has been overtaken
   by the publication's current state (`WebhookEventGuard`) and, if so,
   drops it without sending or recording anything: a `published` (or
   `production`) retry for a publication that has since been unpublished
   (or published), an `unpublished` retry for one published again, or an
   `updated` retry after an unpublish was recorded. Otherwise a delayed retry
   could arrive after a newer delivery and revert it on the receiving end.
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
for it (or `"updated"` if it was never sent before). If that last event no
longer matches the publication's state (e.g. `"production"` for an article
published since), `"published"` or `"unpublished"` is sent instead, matching
the current state. Unlike the automatic listeners, this works even while the
plugin is disabled, as long as a webhook URL is configured. Useful after a
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
  to run again on a later update that doesn't change the schema). Until
  then the plugin keeps working without it - no status is shown or recorded,
  and therefore no `updated` webhooks are sent - and the plugin description
  in the plugin grid points out the missing step.

## Known limitations / open items

- **No SSRF protection:** the target URL is not checked against private or
  internal addresses. This is intentional for local development
  (`localhost`, `127.0.0.1` are accepted on purpose), but it widens the
  attack surface in production if a journal manager can enter arbitrary
  URLs.
- **A galley file replacement is covered indirectly, not a bare metadata edit
  on an existing file revision:** uploading a new revision of a galley's file
  goes through `SubmissionFileRepository::add()`, which itself calls
  `Repo::galley()->edit()` to point the galley at the new revision - so the
  normal "replace this galley's PDF" action is caught by the `Galley::edit`
  listener above. What is *not* caught: OJS's core `SubmissionFile`
  repository fires hooks for `add`/`delete` but not for `edit` - there is no
  `SubmissionFile::edit` hook to listen on - so a change to an *existing*
  file revision's own metadata (not a new revision, and not through the
  galley form) would not trigger an `"updated"` webhook.
- **Deleting a galley's file does not trigger an update either:** OJS core
  works around a known bug ([pkp/pkp-lib#5740](https://github.com/pkp/pkp-lib/issues/5740))
  by nulling the galley's `submissionFileId` *in place* before calling
  `Repo::galley()->edit()` (`classes/submissionFile/Repository.php`,
  `deleteRelatedSubmissionFileObjects()`). By the time `Galley::edit` fires,
  both the "old" and "new" galley objects already report
  `submissionFileId: null`, so the content-change check above sees no
  difference and skips the webhook. Net effect: removing the underlying file
  of a published galley (leaving an empty galley behind, as opposed to
  replacing the file) is not reported. Narrow edge case, left as a known
  limitation rather than special-cased against a core workaround that could
  change at any time; `SubmissionFileDeleted` (already fired by core) would
  be the hook to add this through if it turns out to matter in practice.
- **No GND field for authors:** OJS has no built-in GND identifier field, so
  it cannot be included in the `authors` payload. Decided not to add one -
  the `orcid`/`orcidVerified` fields already on each author are considered
  a sufficient identifier.
- **`roleKey` is `null` for editorial-office-created user groups:** only
  OJS's built-in default groups (Author, Reviewer, ...) carry a
  `nameLocaleKey`. A custom group (e.g. a journal's own "Translator" group)
  has none, so `roleKey` is `null` for its members - the receiving end falls
  back to linking as an author with a warning in that case.

## Development

`WebhookUrlValidator` (the http(s)-only URL check shared by `WebhookPlugin`'s
enable-gate and `WebhookSettingsForm`'s field validator), `WebhookPayload`
(date formatting, keyword reduction, HMAC signature computation and response
normalization used by `SendWebhookJob`), `WebhookStatusFormatter` (warning-code formatting used
by `WebhookPlugin::formatStatusHtml()`) and `WebhookEventGuard` (the
stale-event check used by `SendWebhookJob` and the resend action) have no OJS/PKP dependency and are
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
├── WebhookPayload.php          Pure payload helpers (date format, keywords, HMAC signature, response parsing)
├── WebhookStatusFormatter.php  Pure warning-code formatting for the status display
├── WebhookEventGuard.php       Pure check whether a queued event is outdated
├── SendWebhookJob.php          Async queue job for the HTTP POST
├── settings.xml                Install defaults (enabled = false)
├── version.xml                 Plugin version information
├── templates/settings.tpl      Smarty template for the settings form
├── templates/resend.tpl        Smarty template for the resend form
├── locale/{de,en}/locale.po    Translations
└── tests/                      PHPUnit tests (WebhookUrlValidator, WebhookPayload, WebhookStatusFormatter, WebhookEventGuard; see Development)
```
