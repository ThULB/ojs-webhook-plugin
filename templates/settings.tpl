{**
 * plugins/generic/webhook/templates/settings.tpl
 *
 * Settings form for the Webhook plugin.
 *}
<script>
	$(function() {ldelim}
		$('#webhookSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
		// The plugin grid's "enable" checkbox is only re-rendered on a fresh page
		// load, so reload after a successful save to reflect the plugin's
		// updated getCanEnable() state (e.g. once a valid webhook URL is set).
		$('#webhookSettingsForm').bind('formSubmitted', function() {ldelim}
			window.location.reload();
		{rdelim});
	{rdelim});
</script>

<form class="pkp_form" id="webhookSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{fbvFormArea id="webhookPluginSettings"}
		{fbvFormSection title="plugins.generic.webhook.settings.webhookUrl" description="plugins.generic.webhook.settings.webhookUrl.description"}
			{fbvElement type="text" id="webhookUrl" value=$webhookUrl maxlength="255"}
		{/fbvFormSection}
		{fbvFormSection title="plugins.generic.webhook.settings.webhookSecret" description="plugins.generic.webhook.settings.webhookSecret.description"}
			{* Never rendered back into the page: the field stays empty and an empty submission keeps the stored secret (see WebhookSettingsForm::execute()). *}
			{fbvElement type="text" password=true id="webhookSecret" value="" maxlength="255" autocomplete="new-password"}
		{/fbvFormSection}
		{if $webhookSecretIsSet}
			{fbvFormSection list="true" description="plugins.generic.webhook.settings.webhookSecret.isSet"}
				{fbvElement type="checkbox" id="clearWebhookSecret" checked=false label="plugins.generic.webhook.settings.webhookSecret.clear"}
			{/fbvFormSection}
		{/if}
		{fbvFormSection list="true"}
			{fbvElement type="checkbox" id="requireDoiBeforePublish" checked=$requireDoiBeforePublish label="plugins.generic.webhook.settings.requireDoiBeforePublish"}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons}
</form>
