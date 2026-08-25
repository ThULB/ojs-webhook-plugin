<?php

/**
 * @file WebhookSettingsForm.php
 *
 * @brief Form for journal managers to configure the publish webhook.
 */

namespace APP\plugins\generic\webhook;

use APP\core\Application;
use APP\notification\NotificationManager;
use PKP\core\PKPApplication;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorCustom;
use PKP\form\validation\FormValidatorPost;
use PKP\notification\Notification;

class WebhookSettingsForm extends Form
{
    public WebhookPlugin $plugin;

    public function __construct(WebhookPlugin $plugin)
    {
        parent::__construct($plugin->getTemplateResource('settings.tpl'));
        $this->plugin = $plugin;

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
        // FormValidatorUrl also applies jQuery Validation's client-side "url" rule, whose regex
        // requires a dotted TLD and explicitly rejects private/loopback hosts (e.g. localhost,
        // 127.0.0.1) - too strict for webhook endpoints used during local development.
        // Type is "optional" (not "required") so managers can clear the URL again to reset the
        // configuration; WebhookPlugin::hasValidWebhookConfig() treats an empty value as
        // "not configured" and disables the plugin's "Enable" toggle accordingly.
        $this->addCheck(new FormValidatorCustom(
            $this,
            'webhookUrl',
            'optional',
            'plugins.generic.webhook.settings.webhookUrl.invalid',
            fn (string $value): bool => filter_var($value, FILTER_VALIDATE_URL) !== false
                && in_array(parse_url($value, PHP_URL_SCHEME), ['http', 'https'], true)
        ));
    }

    protected function getContextId(): int
    {
        return Application::get()->getRequest()->getContext()?->getId() ?? PKPApplication::CONTEXT_SITE;
    }

    /**
     * @copydoc Form::initData
     */
    public function initData()
    {
        $contextId = $this->getContextId();
        $this->setData('webhookUrl', $this->plugin->getSetting($contextId, 'webhookUrl'));
        $this->setData('webhookSecret', $this->plugin->getSetting($contextId, 'webhookSecret'));
        parent::initData();
    }

    /**
     * @copydoc Form::readInputData
     */
    public function readInputData()
    {
        $this->readUserVars(['webhookUrl', 'webhookSecret']);
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
        $contextId = $this->getContextId();
        $this->plugin->updateSetting($contextId, 'webhookUrl', trim((string) $this->getData('webhookUrl')));
        $this->plugin->updateSetting($contextId, 'webhookSecret', trim((string) $this->getData('webhookSecret')) ?: null);

        $request = Application::get()->getRequest();
        $notificationMgr = new NotificationManager();
        $notificationMgr->createTrivialNotification(
            $request->getUser()->getId(),
            Notification::NOTIFICATION_TYPE_SUCCESS,
            ['contents' => __('common.changesSaved')]
        );

        return parent::execute(...$functionArgs);
    }
}
