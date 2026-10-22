<?php

namespace APP\plugins\generic\webhook;

use APP\core\Application;
use APP\decision\Decision;
use APP\facades\Repo;
use APP\publication\Publication;
use APP\submission\Submission;
use Illuminate\Support\Facades\Event;
use PKP\components\forms\FieldHTML;
use PKP\components\forms\FormComponent;
use PKP\context\Context;
use PKP\core\JSONMessage;
use PKP\galley\Galley;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\DecisionAdded;
use PKP\observers\events\MetadataChanged;
use PKP\observers\events\PublicationPublished;
use PKP\observers\events\PublicationUnpublished;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;
use PKP\submission\PKPSubmission;

class WebhookPlugin extends GenericPlugin
{
    /**
     * Publication IDs a "published" webhook was already dispatched for in this
     * request. OJS fires MetadataChanged a second time right after publishing
     * (IssueGridHandler and the PublishSubmissions scheduled task both do this
     * in the same request as the PublicationPublished event), which would
     * otherwise queue a redundant "updated" webhook for the same publication.
     */
    protected array $publishedInThisRequest = [];

    public function getDisplayName()
    {
        return __('plugins.generic.webhook.displayName');
    }

    public function getDescription()
    {
        $description = __('plugins.generic.webhook.description');

        if (!$this->hasValidWebhookConfig()) {
            $description .= ' ' . __('plugins.generic.webhook.description.requiresConfig');
        } elseif (!$this->hasRecommendedDoiConfig()) {
            $description .= ' ' . __('plugins.generic.webhook.description.doiConfigWarning');
        }

        return $description;
    }

    /**
     * @copydoc Plugin::register()
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }

        if ($this->getEnabled($mainContextId)) {
            Event::listen(
                DecisionAdded::class,
                function (DecisionAdded $event) {
                    if ($event->decisionType->getDecision() === Decision::SEND_TO_PRODUCTION) {
                        $publication = $event->submission->getCurrentPublication();
                        $this->dispatchWebhook('production', $event->context->getId(), $event->submission->getId(), $publication->getId());
                    }
                }
            );

            Event::listen(
                PublicationPublished::class,
                function (PublicationPublished $event) {
                    $publication = $event->publication;
                    $oldPublication = $event->oldPublication;
                    $submission = $event->submission;

                    $wasPublished = $oldPublication->getData('status') === PKPSubmission::STATUS_PUBLISHED;
                    $isPublished = $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED;

                    if (!$wasPublished && $isPublished) {
                        $this->publishedInThisRequest[$publication->getId()] = true;
                        $this->dispatchWebhook('published', $submission->getData('contextId'), $submission->getId(), $publication->getId());
                    }
                }
            );

            Event::listen(
                MetadataChanged::class,
                function (MetadataChanged $event) {
                    $submission = $event->submission;
                    $publication = $submission->getCurrentPublication();

                    if (!empty($this->publishedInThisRequest[$publication->getId()])) {
                        return;
                    }

                    // Only resend if this publication was already sent at least once
                    // before - tracked via a recorded zsId, not doiId: the
                    // Zeitschriftenserver may have accepted the submission without
                    // being able to mint a DOI (DOI_FAILED/DOI_DISABLED), in which
                    // case doiId never gets set but a correction still needs to
                    // reach it. success alone doesn't work either, since it flips
                    // to false again after any later failed attempt.
                    $status = WebhookStatus::get($publication->getId());
                    if ($status && $status['zsId']) {
                        $this->dispatchWebhook('updated', $submission->getData('contextId'), $submission->getId(), $publication->getId());
                    }
                }
            );

            Event::listen(
                PublicationUnpublished::class,
                function (PublicationUnpublished $event) {
                    $this->dispatchWebhook('unpublished', $event->context->getId(), $event->submission->getId(), $event->publication->getId());
                }
            );

            Hook::add('Galley::add', function (string $hookName, array $args) {
                /** @var Galley $galley */
                $galley = $args[0];
                $this->handleGalleyChanged($galley->getData('publicationId'));
            });

            Hook::add('Galley::edit', function (string $hookName, array $args) {
                /** @var Galley $newGalley */
                $newGalley = $args[0];
                /** @var Galley $oldGalley */
                $oldGalley = $args[1];

                // Skip edits that don't change anything the webhook payload (or a reader) cares
                // about - a drag-and-drop reorder in the galley grid, for instance, only changes
                // `seq` and would otherwise queue a pointless "updated" webhook on every reorder.
                if (!$this->galleyContentChanged($oldGalley, $newGalley)) {
                    return;
                }

                $this->handleGalleyChanged($newGalley->getData('publicationId'));
            });

            Hook::add('Publication::validatePublish', function (string $hookName, array $args) {
                $errors = & $args[0];
                /** @var Publication $publication */
                $publication = $args[1];
                /** @var Submission $submission */
                $submission = $args[2];

                $contextId = $submission->getData('contextId');

                if (!$this->getSetting($contextId, 'requireDoiBeforePublish')) {
                    return Hook::CONTINUE;
                }

                if (!$publication->getData('doiId')) {
                    $errors['webhookDoi'] = [__('plugins.generic.webhook.validatePublish.missingDoi')];
                }

                return Hook::CONTINUE;
            });

            Hook::add('Form::config::before', function (string $hookName, FormComponent $form) {
                if ($form->id !== 'publicationIdentifiers') {
                    return;
                }

                $status = WebhookStatus::get($form->publication->getId());
                if (!$status) {
                    return;
                }

                $form->addField(new FieldHTML('webhookStatus', [
                    'label' => __('plugins.generic.webhook.status.label'),
                    'description' => $this->formatStatusHtml($status),
                ]));
            });
        }

        return true;
    }

    /**
     * Render the last recorded webhook status as the description of a
     * read-only FieldHTML on the Publication tab's "Identifiers" form.
     */
    protected function formatStatusHtml(array $status): string
    {
        $lines = [$status['success']
            ? __('plugins.generic.webhook.status.sent', ['event' => $status['event'], 'date' => $status['sentAt']])
            : __('plugins.generic.webhook.status.failed', ['event' => $status['event'], 'date' => $status['sentAt']])];

        if ($status['zsId']) {
            $lines[] = __('plugins.generic.webhook.status.zsId', ['id' => $status['zsId']]);
        }

        $warnings = WebhookStatusFormatter::normalizeWarnings($status['warnings'] ?? []);
        if ($warnings) {
            $lines[] = __('plugins.generic.webhook.status.warnings');
            foreach ($warnings as $warning) {
                $localeKey = WebhookStatusFormatter::localeKeyForCode($warning['code']);
                $label = $localeKey ? __($localeKey) : $warning['code'];

                $lines[] = $warning['detail']
                    ? __('plugins.generic.webhook.status.warning', ['label' => $label, 'detail' => $warning['detail']])
                    : __('plugins.generic.webhook.status.warning.noDetail', ['label' => $label]);
            }
        }

        if ($status['error']) {
            $lines[] = __('plugins.generic.webhook.status.error', ['error' => $status['error']]);
        }

        return implode('<br>', array_map('htmlspecialchars', $lines));
    }

    /**
     * Re-dispatch the webhook for a submission's current publication, using
     * whatever event was last sent for it (defaulting to "updated" if it was
     * never sent before). Used by the "resend" action in the plugin grid.
     *
     * Returns false if the submission/publication could not be found, or if
     * no valid webhook URL is configured - the resend action is shown
     * regardless of whether the plugin has been configured yet, so this must
     * be checked here rather than assumed.
     */
    public function resendWebhook(int $submissionId): bool
    {
        $submission = Repo::submission()->get($submissionId);
        if (!$submission || $submission->getData('contextId') !== $this->getCurrentContextId()) {
            return false;
        }

        $publication = $submission->getCurrentPublication();
        if (!$publication) {
            return false;
        }

        $status = WebhookStatus::get($publication->getId());
        $event = $status['event'] ?? 'updated';

        return $this->dispatchWebhook($event, $submission->getData('contextId'), $submissionId, $publication->getId());
    }

    /**
     * Whether a galley edit touched a field the webhook payload (or a reader) actually cares
     * about: its file, its label, its locale, or a remote URL it points to instead of a file.
     * Deliberately excludes `seq` and other purely cosmetic/grid-internal fields.
     */
    protected function galleyContentChanged(Galley $old, Galley $new): bool
    {
        foreach (['label', 'locale', 'submissionFileId', 'urlRemote'] as $field) {
            if ($old->getData($field) !== $new->getData($field)) {
                return true;
            }
        }

        return false;
    }

    /**
     * React to a galley being added or edited directly on the Fahnen/galley form, which (unlike
     * the upload wizard's PKPManageFileApiHandler::saveMetadata()) does not fire MetadataChanged.
     * Without this, a corrected galley (e.g. a replaced PDF) on an already-published publication
     * never reaches the Zeitschriftenserver.
     *
     * Sends the same "updated" webhook as MetadataChanged, under the same condition: only once the
     * publication is actually published, and only if it was already accepted by the
     * Zeitschriftenserver at least once before (a zsId on record).
     */
    protected function handleGalleyChanged(?int $publicationId): void
    {
        if (!$publicationId) {
            return;
        }

        $publication = Repo::publication()->get($publicationId);
        if (!$publication || $publication->getData('status') !== PKPSubmission::STATUS_PUBLISHED) {
            return;
        }

        $submission = Repo::submission()->get($publication->getData('submissionId'));
        if (!$submission) {
            return;
        }

        $status = WebhookStatus::get($publicationId);
        if (!$status || !$status['zsId']) {
            return;
        }

        $this->dispatchWebhook('updated', $submission->getData('contextId'), $submission->getId(), $publicationId);
    }

    /**
     * Queue a webhook notification for an asynchronous send, if a webhook URL is
     * configured. Returns whether a job was actually queued.
     */
    protected function dispatchWebhook(string $event, int $contextId, int $submissionId, int $publicationId): bool
    {
        if (!$this->hasValidWebhookConfig($contextId)) {
            return false;
        }

        $webhookUrl = $this->getSetting($contextId, 'webhookUrl');
        $webhookSecret = $this->getSetting($contextId, 'webhookSecret');

        SendWebhookJob::dispatch($event, $submissionId, $publicationId, $webhookUrl, $webhookSecret ?: null, $this->getPluginVersionString());

        return true;
    }

    /**
     * The installed version of this plugin, for the User-Agent header
     * (SendWebhookJob has no access to the plugin instance itself, since it
     * runs out of process on the job queue).
     */
    protected function getPluginVersionString(): string
    {
        $version = $this->getCurrentVersion();

        return $version ? $version->getVersionString() : 'unknown';
    }

    /**
     * Whether a syntactically valid webhook URL is configured for the given context
     * (or the current context/site if none is given).
     */
    protected function hasValidWebhookConfig(?int $contextId = null): bool
    {
        if ($contextId === null) {
            $contextId = $this->getCurrentContextId();
        }

        return WebhookUrlValidator::isValid($this->getSetting($contextId, 'webhookUrl'));
    }

    /**
     * Whether the current journal's DOI settings match what the receiving end expects:
     * DOIs enabled for publications, assigned manually (so the DOI returned by the
     * webhook response can be recorded), and never auto-generated by OJS itself.
     */
    protected function hasRecommendedDoiConfig(): bool
    {
        $context = Application::get()->getRequest()->getContext();
        if (!$context) {
            return true;
        }

        if (!$context->isDoiTypeEnabled(Repo::doi()::TYPE_PUBLICATION)) {
            return false;
        }

        return $context->getData(Context::SETTING_DOI_SUFFIX_TYPE) === Repo::doi()::SUFFIX_MANUAL
            && $context->getData(Context::SETTING_DOI_CREATION_TIME) === Repo::doi()::CREATION_TIME_NEVER;
    }

    /**
     * @copydoc Plugin::getCanEnable()
     */
    public function getCanEnable()
    {
        return $this->hasValidWebhookConfig();
    }

    /**
     * @copydoc Plugin::getInstallMigration()
     */
    public function getInstallMigration()
    {
        return new WebhookStatusMigration();
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);

        $router = $request->getRouter();
        $resendAction = new LinkAction(
            'resend',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'resend',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]
                ),
                __('plugins.generic.webhook.linkAction.resend')
            ),
            __('plugins.generic.webhook.linkAction.resend'),
            null
        );

        $settingsAction = new LinkAction(
            'settings',
            new AjaxModal(
                $router->url(
                    $request,
                    null,
                    null,
                    'manage',
                    null,
                    [
                        'verb' => 'settings',
                        'plugin' => $this->getName(),
                        'category' => 'generic',
                    ]
                ),
                $this->getDisplayName()
            ),
            __('manager.plugins.settings'),
            null
        );

        array_unshift($actions, $resendAction);
        array_unshift($actions, $settingsAction);

        return $actions;
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $form = new WebhookSettingsForm($this);

                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                }

                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
            case 'resend':
                $form = new WebhookResendForm($this);

                if ($request->getUserVar('resend')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                }

                $form->initData();
                return new JSONMessage(true, $form->fetch($request));
        }

        return parent::manage($args, $request);
    }
}
