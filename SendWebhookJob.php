<?php

/**
 * @file SendWebhookJob.php
 *
 * @brief Queue job that notifies an external webhook when a submission is sent to
 * production or a publication is published. Runs asynchronously so the triggering
 * editorial action never blocks on the remote endpoint's availability or response time.
 */

namespace APP\plugins\generic\webhook;

use APP\core\Application;
use APP\facades\Repo;
use APP\oai\ojs\OAIDAO;
use APP\publication\Publication;
use PKP\config\Config;
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;
use PKP\submission\PKPSubmission;
use PKP\userGroup\UserGroup;

class SendWebhookJob extends BaseJob
{
    /**
     * The Zeitschriftenserver endpoint is idempotent per submissionId/publicationId,
     * so retries on top of BaseJob's defaults are safe - widen them to ride out
     * longer outages of the receiving end.
     */
    public $tries = 5;

    public function backoff(): array
    {
        return [30, 120, 600, 1800];
    }

    public function __construct(
        protected string $event,
        protected int $submissionId,
        protected int $publicationId,
        protected string $webhookUrl,
        protected ?string $webhookSecret
    ) {
        parent::__construct();
    }

    public function handle(): void
    {
        $submission = Repo::submission()->get($this->submissionId);
        $publication = Repo::publication()->get($this->publicationId);

        if (!$submission || !$publication) {
            throw new JobException(JobException::INVALID_PAYLOAD);
        }

        $contextId = $submission->getData('contextId');

        $issue = null;
        if ($issueId = $publication->getData('issueId')) {
            $issue = Repo::issue()->get($issueId);
        }

        $section = null;
        if ($sectionId = $publication->getData('sectionId')) {
            $section = Repo::section()->get($sectionId, $contextId);
        }

        /** @var OAIDAO $oaiDao */
        $oaiDao = DAORegistry::getDAO('OAIDAO');
        $journal = $oaiDao->getJournal($contextId);
        $journalPath = $journal?->getPath();

        $request = Application::get()->getRequest();

        // The surface language of whoever triggers the webhook (e.g. the editor
        // clicking "Publish") must not affect the payload - always resolve every
        // localized field against the submission's own primary language instead.
        $locale = $submission->getData('locale');

        $payload = [
            'event' => $this->event,
            'source' => [
                // Not $request->getBaseUrl(): that reflects whatever host served
                // *this* request (or, from a CLI worker, falls back to this same
                // config value anyway), which can vary across requests behind a
                // proxy/load balancer. The Zeitschriftenserver identifies articles
                // by ojsBaseUrl|contextId|submissionId, so an unstable value here
                // makes it re-create the article under a second DOI.
                'ojsBaseUrl' => Config::getVar('general', 'base_url'),
                'journalPath' => $journalPath,
                'contextId' => $contextId,
            ],
            'submissionId' => $this->submissionId,
            'publicationId' => $this->publicationId,
            'version' => $publication->getData('version'),
            'url' => $journalPath ? $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $journalPath, 'article', 'view', [$this->submissionId]) : null,
            'locale' => $locale,
            'title' => $publication->getData('title', $locale),
            'prefix' => $publication->getData('prefix', $locale),
            'subtitle' => $publication->getData('subtitle', $locale),
            'abstract' => $publication->getData('abstract', $locale),
            'keywords' => $this->getKeywordsPayload($publication, $locale),
            'pages' => $publication->getData('pages'),
            'datePublished' => $this->formatDate($publication->getData('datePublished')),
            'license' => [
                'url' => $publication->getData('licenseUrl'),
                'copyrightHolder' => $publication->getData('copyrightHolder', $locale),
                'copyrightYear' => $publication->getData('copyrightYear'),
            ],
            'section' => $section ? [
                'id' => $section->getId(),
                'title' => $section->getTitle($locale),
            ] : null,
            'issue' => $issue ? [
                'id' => $issue->getId(),
                'volume' => $issue->getVolume(),
                'number' => $issue->getNumber(),
                'year' => $issue->getYear(),
                'title' => $issue->getTitle($locale),
                'datePublished' => $this->formatDate($issue->getDatePublished()),
            ] : null,
            'doi' => $this->getCurrentDoi($publication),
            'authors' => $this->getAuthorsPayload($publication, $locale),
            // No PDF with a DOI exists yet before the publication is actually published - not just
            // sent to production: an "updated" webhook can fire for a publication still in
            // production (e.g. a metadata edit after a "production" send), where the galley/download
            // URL is not yet publicly reachable.
            'galleys' => (in_array($this->event, ['published', 'updated'], true) && $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED)
                ? $this->getGalleysPayload($publication, $request, $journalPath)
                : null,
        ];

        $body = json_encode($payload);

        $headers = ['Content-Type: application/json'];
        // Sign the body instead of sending the secret itself, so it never
        // crosses the wire and a captured request can't be replayed with a
        // tampered payload.
        if ($signature = WebhookPayload::signatureValue($body, $this->webhookSecret)) {
            $headers[] = 'X-Webhook-Signature: sha256=' . $signature;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_USERAGENT => 'OJS-Webhook-Plugin/0.1 (+https://github.com/ThULB/ojs-webhook-plugin)',
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 15,
            // Defense in depth: WebhookPlugin/WebhookSettingsForm already restrict
            // the configured URL to http(s), but enforce it here too in case that
            // check is ever bypassed (e.g. a value written directly to the DB).
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        ]);

        $result = curl_exec($ch);
        $curlErrno = curl_errno($ch);
        $curlError = curl_error($ch);
        $statusCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($curlErrno !== 0) {
            throw new \Exception("Webhook request failed for submission {$this->submissionId}: {$curlError}");
        }

        if ($statusCode < 200 || $statusCode >= 300) {
            throw new \Exception("Webhook returned HTTP {$statusCode} for submission {$this->submissionId}: {$result}");
        }

        $response = json_decode((string) $result, true);
        $this->recordDoiFromResponse($publication, $contextId, $response);

        WebhookStatus::recordSuccess(
            $this->publicationId,
            $this->event,
            $response['id'] ?? null,
            $response['url'] ?? null,
            $response['warnings'] ?? []
        );
    }

    /**
     * Called by the queue once all of $tries' attempts are exhausted, so a
     * permanently failing endpoint shows up as an error on the publication
     * instead of only in the job/failure logs.
     */
    public function failed(\Throwable $exception): void
    {
        WebhookStatus::recordFailure($this->publicationId, $this->event, $exception->getMessage());
    }

    /**
     * Build the per-author payload (name, role, ORCID, affiliations) instead of the
     * collapsed "Lastname et al." author string, so the receiving end can link
     * individual persons (e.g. via ORCID/GND) and DataCite's mandatory "creators"
     * requirement can be satisfied.
     */
    protected function getAuthorsPayload(Publication $publication, string $locale): array
    {
        $authors = [];

        foreach ($publication->getData('authors') ?? [] as $author) {
            $userGroup = $author->getUserGroup();

            $authors[] = [
                'seq' => $author->getSequence(),
                // OJS has no dedicated author/translator distinction (see README); the user
                // group name in the submission's language is kept as a display fallback only.
                // The receiving end should key off userGroupId/roleId instead of guessing from
                // this string.
                'role' => $userGroup?->getLocalizedData('name', $locale),
                'userGroupId' => $author->getUserGroupId(),
                // The PKP role constant (e.g. 65536 for ROLE_ID_AUTHOR). Note this does NOT
                // distinguish between user groups that share the same role - e.g. "Author" and a
                // custom "Translator" group are both ROLE_ID_AUTHOR - so userGroupId remains the
                // only value that identifies the group itself.
                'roleId' => $userGroup?->roleId,
                // Best-effort, locale-independent label: only non-null if the journal actually has
                // the en_US locale installed (see README). getLocalizedData() falls back to the
                // "best" available locale by default, which would defeat the point here, so the
                // lookup is forced to an exact match instead.
                'roleName' => [
                    'en' => $userGroup?->getLocalizedData('name', 'en_US', UserGroup::LOCALE_MATCH_STRICT),
                ],
                'givenName' => $author->getGivenName($locale),
                'familyName' => $author->getFamilyName($locale),
                'orcid' => $author->getData('orcid'),
                'orcidVerified' => (bool) $author->getData('orcidIsVerified'),
                'affiliations' => array_map(
                    fn ($affiliation) => [
                        'name' => $affiliation->getLocalizedName($locale),
                        'ror' => $affiliation->getRor(),
                    ],
                    $author->getAffiliations()
                ),
            ];
        }

        return $authors;
    }

    /**
     * Reduce the controlled-vocabulary keyword entries (`[{"name": ..., "source": ...,
     * "identifier": ...}]`) to a plain list of strings, as agreed with the
     * Zeitschriftenserver side.
     */
    protected function getKeywordsPayload(Publication $publication, string $locale): array
    {
        return WebhookPayload::reduceKeywords($publication->getData('keywords', $locale) ?? []);
    }

    /**
     * The DOI OJS already has on file for this publication, if any, so the receiving
     * end can compare it against the one it manages itself.
     */
    protected function getCurrentDoi(Publication $publication): ?string
    {
        $doiId = $publication->getData('doiId');
        if (!$doiId) {
            return null;
        }

        return Repo::doi()->get($doiId)?->getDoi();
    }

    protected function formatDate(?string $date): ?string
    {
        return WebhookPayload::formatDate($date);
    }

    /**
     * Build the galley payload. Only relevant once a publication has actually been
     * published: before that, no PDF with the registered DOI exists yet, and the
     * download URL would not be publicly reachable (see README).
     */
    protected function getGalleysPayload(Publication $publication, PKPRequest $request, ?string $journalPath): array
    {
        if (!$journalPath) {
            return [];
        }

        $galleys = Repo::galley()->getCollector()->filterByPublicationIds([$publication->getId()])->getMany();

        $result = [];
        foreach ($galleys as $galley) {
            $submissionFileId = $galley->getData('submissionFileId');
            $submissionFile = $submissionFileId ? Repo::submissionFile()->get($submissionFileId) : null;

            $result[] = [
                'id' => $galley->getId(),
                'label' => $galley->getLabel(),
                'mimeType' => $submissionFile?->getData('mimetype'),
                'fileId' => $submissionFileId,
                'url' => $request->getDispatcher()->url($request, Application::ROUTE_PAGE, $journalPath, 'article', 'download', [$this->submissionId, $galley->getId()]),
            ];
        }

        return $result;
    }

    /**
     * Record the DOI the receiving end minted, if any, so it is in place in OJS
     * before the article is published. Never overwrites a DOI that already differs
     * from the one reported back, since that would silently discard an existing
     * registration; such a mismatch is logged for manual follow-up instead.
     */
    protected function recordDoiFromResponse(Publication $publication, int $contextId, ?array $response): void
    {
        $doi = $response['doi'] ?? null;
        if (!$doi) {
            return;
        }

        $existingDoiId = $publication->getData('doiId');
        if ($existingDoiId) {
            $existingDoi = Repo::doi()->get($existingDoiId);
            if ($existingDoi && $existingDoi->getData('doi') === $doi) {
                return;
            }

            error_log(sprintf(
                'SendWebhookJob: Zeitschriftenserver returned DOI "%s" for publication %d, but DOI "%s" is already set. Not overwriting.',
                $doi,
                $publication->getId(),
                $existingDoi?->getData('doi') ?? '(unknown)'
            ));
            return;
        }

        $doiId = Repo::doi()->add(Repo::doi()->newDataObject([
            'doi' => $doi,
            'contextId' => $contextId,
        ]));

        Repo::publication()->edit($publication, ['doiId' => $doiId]);
    }
}
