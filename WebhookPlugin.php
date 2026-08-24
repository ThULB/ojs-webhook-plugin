<?php

namespace APP\plugins\generic\webhook;

use Illuminate\Support\Facades\Event;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\observers\events\PublicationPublished;
use PKP\plugins\GenericPlugin;
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
                PublicationPublished::class,
                function (PublicationPublished $event) {
                    $publication = $event->publication;
                    $oldPublication = $event->oldPublication;
                    $submission = $event->submission;

                    $wasPublished = $oldPublication->getData('status') === PKPSubmission::STATUS_PUBLISHED;
                    $isPublished = $publication->getData('status') === PKPSubmission::STATUS_PUBLISHED;

                    if (!$wasPublished && $isPublished) {
                        $this->dispatchWebhook($submission->getData('contextId'), $submission->getId(), $publication->getId());
                    }
                }
            );
        }

        return true;
    }

    /**
     * Queue a webhook notification for an asynchronous send, if a webhook URL is configured.
     */
    protected function dispatchWebhook(int $contextId, int $submissionId, int $publicationId): void
    {
        if (!$this->hasValidWebhookConfig($contextId)) {
            return;
        }

        $webhookUrl = $this->getSetting($contextId, 'webhookUrl');
        $webhookSecret = $this->getSetting($contextId, 'webhookSecret');

        SendWebhookJob::dispatch($submissionId, $publicationId, $webhookUrl, $webhookSecret ?: null);
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

        $webhookUrl = $this->getSetting($contextId, 'webhookUrl');

        return is_string($webhookUrl) && filter_var($webhookUrl, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * @copydoc Plugin::getCanEnable()
     */
    public function getCanEnable()
    {
        return $this->hasValidWebhookConfig();
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $actionArgs)
    {
        $actions = parent::getActions($request, $actionArgs);

        $router = $request->getRouter();
        $linkAction = new LinkAction(
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

        array_unshift($actions, $linkAction);

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
        }

        return parent::manage($args, $request);
    }
}
