<?php

/**
 * @file SendWebhookJob.php
 *
 * @brief Queue job that notifies an external webhook when a publication is published.
 * Runs asynchronously so the editor's "Publish" action never blocks on the
 * remote endpoint's availability or response time.
 */

namespace APP\plugins\generic\webhook;

use APP\facades\Repo;
use APP\oai\ojs\OAIDAO;
use PKP\db\DAORegistry;
use PKP\job\exceptions\JobException;
use PKP\jobs\BaseJob;

class SendWebhookJob extends BaseJob
{
    public function __construct(
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

        $issue = null;
        if ($issueId = $publication->getData('issueId')) {
            $issue = Repo::issue()->get($issueId);
        }

        $section = null;
        if ($sectionId = $publication->getData('sectionId')) {
            $section = Repo::section()->get($sectionId, $submission->getData('contextId'));
        }

        /** @var OAIDAO $oaiDao */
        $oaiDao = DAORegistry::getDAO('OAIDAO');
        $journal = $oaiDao->getJournal($submission->getData('contextId'));

        $payload = [
            'author' => $publication->getShortAuthorString(),
            'article_title' => $publication->getLocalizedTitle(),
            'published_date' => $issue?->getDatePublished() ? date('Y-m-d', strtotime($issue->getDatePublished())) : null,
            'issue_title' => $issue?->getLocalizedTitle(),
            'abstract' => $publication->getLocalizedData('abstract'),
            'rich_pages' => $section?->getLocalizedTitle(),
            'keywords' => $publication->getLocalizedData('keywords'),
            'legal' => $publication->getData('rights'),
            'published_institute' => $journal?->getData('publisherInstitution'),
            'issue_volume' => $issue?->getVolume(),
            'issue_number' => $issue?->getNumber(),
            'issue_year' => $issue?->getYear(),
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
    }
}
