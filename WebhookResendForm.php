<?php

/**
 * @file WebhookResendForm.php
 *
 * @brief Form for journal managers to manually resend the webhook for a submission
 * (e.g. after a permanently failed delivery - see WebhookStatus/SendWebhookJob::failed()).
 */

namespace APP\plugins\generic\webhook;

use APP\core\Application;
use APP\facades\Repo;
use APP\notification\NotificationManager;
use PKP\core\PKPApplication;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\notification\Notification;

class WebhookResendForm extends Form
{
    public WebhookPlugin $plugin;

    public function __construct(WebhookPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('resend.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        $this->addCheck(new FormValidatorCustom(
            $this,
            'resendSubmissionId',
            'required',
            'plugins.generic.webhook.resend.notFound',
            fn (string $value): bool => $this->findSubmission((int) $value) !== null
        ));
    }

    /**
     * @copydoc Form::readInputData
     */
    public function readInputData()
    {
        $this->readUserVars(['resendSubmissionId']);
    }

    /**
     * @copydoc Form::fetch
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = \APP\template\TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->plugin->getName());
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute
     */
    public function execute(...$functionArgs)
    {
        $queued = $this->plugin->resendWebhook((int) $this->getData('resendSubmissionId'));

        $request = Application::get()->getRequest();
        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            $request->getUser()->getId(),
            $queued ? Notification::NOTIFICATION_TYPE_SUCCESS : Notification::NOTIFICATION_TYPE_ERROR,
            ['contents' => __($queued ? 'plugins.generic.webhook.resend.queued' : 'plugins.generic.webhook.resend.notConfigured')]
        );

        return parent::execute(...$functionArgs);
    }

    /**
     * The submission with the given ID, if it belongs to the current context.
     */
    protected function findSubmission(int $submissionId): ?\APP\submission\Submission
    {
        $contextId = Application::get()->getRequest()->getContext()?->getId() ?? PKPApplication::CONTEXT_SITE;
        $submission = Repo::submission()->get($submissionId);

        return ($submission && $submission->getData('contextId') === $contextId) ? $submission : null;
    }
}
