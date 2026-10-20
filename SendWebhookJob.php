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
use PKP\core\PKPRequest;
use PKP\db\DAORegistry;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;

class SendWebhookJob extends BaseJob
{
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
                'ojsBaseUrl' => $request->getBaseUrl(),
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
            // No PDF with a DOI exists yet when a submission is only sent to production.
            'galleys' => in_array($this->event, ['published', 'updated'], true)
                ? $this->getGalleysPayload($publication, $request, $journalPath)
                : null,
        ];

        $headers = ['Content-Type: application/json'];
        if ($this->webhookSecret) {
            $headers[] = 'X-Webhook-Secret: ' . $this->webhookSecret;
        }

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->webhookUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload),
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

        $this->recordDoiFromResponse($publication, $contextId, json_decode((string) $result, true));
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
            $authors[] = [
                'seq' => $author->getSequence(),
                // OJS has no dedicated author/translator distinction (see README); the
                // user group name in the submission's language is the closest honest
                // equivalent available.
                'role' => $author->getUserGroup()?->getLocalizedData('name', $locale),
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
        $keywords = $publication->getData('keywords', $locale) ?? [];

        return array_values(array_filter(array_map(
            fn ($keyword) => $keyword['name'] ?? null,
            $keywords
        )));
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
        return $date ? date('Y-m-d', strtotime($date)) : null;
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
