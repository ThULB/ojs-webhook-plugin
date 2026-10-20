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
                        $this->dispatchWebhook('published', $submission->getData('contextId'), $submission->getId(), $publication->getId());
                    }
                }
            );

            Event::listen(
                MetadataChanged::class,
                function (MetadataChanged $event) {
                    $submission = $event->submission;
                    $publication = $submission->getCurrentPublication();

                    // Only resend if this publication was already sent at least once
                    // (tracked via the DOI a prior production/published webhook got
                    // back) - otherwise the receiving end has nothing to update yet.
                    if ($publication->getData('doiId')) {
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

        if (!empty($status['warnings'])) {
            $codes = WebhookStatusFormatter::warningCodes($status['warnings']);
            if ($codes) {
                $lines[] = __('plugins.generic.webhook.status.warnings', ['warnings' => $codes]);
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

        SendWebhookJob::dispatch($event, $submissionId, $publicationId, $webhookUrl, $webhookSecret ?: null);

        return true;
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
