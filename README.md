# ojs-webhook-plugin

Generic plugin for OJS 3.5 (plugin folder: `webhook`) that sends an HTTP
webhook with the article's metadata to a configurable URL whenever an
article is published for the first time.

## How it works

1. The plugin registers a listener on the core `PublicationPublished` event.
2. Whenever a publication is saved, it checks whether this is the transition
   from *not published* to *published*
   (`oldPublication.status !== PUBLISHED && publication.status === PUBLISHED`).
   Re-saving an already-published article does **not** trigger another
   webhook.
3. If a valid `webhookUrl` is configured, a `SendWebhookJob` is queued on the
   OJS job queue. The actual HTTP request therefore runs asynchronously and
   does not block the "Publish" action in the backend.
4. The job loads the submission, publication, issue, section and journal and
   sends the following JSON payload via `POST`:

   | Field                  | Source                                      |
   |------------------------|----------------------------------------------|
   | `author`               | `Publication::getShortAuthorString()`       |
   | `article_title`        | `Publication::getLocalizedTitle()`          |
   | `published_date`       | `Issue::getDatePublished()` (`Y-m-d`)       |
   | `issue_title`          | `Issue::getLocalizedTitle()`                |
   | `abstract`             | `Publication::getLocalizedData('abstract')` |
   | `rich_pages`           | `Section::getLocalizedTitle()`              |
   | `keywords`             | `Publication::getLocalizedData('keywords')` |
   | `legal`                | `Publication::getData('rights')`            |
   | `published_institute`  | `Journal::getData('publisherInstitution')`  |
   | `issue_volume`         | `Issue::getVolume()`                        |
   | `issue_number`         | `Issue::getNumber()`                        |
   | `issue_year`           | `Issue::getYear()`                          |

   If a `webhookSecret` is configured, it is sent unchanged in the
   `X-Webhook-Secret` header so the receiving endpoint can attribute the
   request to this journal.

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

## Files

```
webhook/
├── WebhookPlugin.php          Plugin class, event listener, enable gate
├── WebhookSettingsForm.php    Settings form (webhook URL/secret)
├── SendWebhookJob.php         Async queue job for the HTTP POST
├── settings.xml               Install defaults (enabled = false)
├── version.xml                Plugin version information
├── templates/settings.tpl     Smarty template for the settings form
└── locale/{de,en}/locale.po   Translations
```
