{**
 * plugins/generic/webhook/templates/resend.tpl
 *
 * Form for manually resending the webhook for a submission.
 *}
<script>
	$(function() {ldelim}
		$('#webhookResendForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="webhookResendForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="resend" resend=true}">
	{csrf}
	{fbvFormArea id="webhookResendFormArea"}
		{fbvFormSection title="plugins.generic.webhook.resend.submissionId" description="plugins.generic.webhook.resend.submissionId.description"}
			{fbvElement type="text" id="resendSubmissionId" value=$resendSubmissionId maxlength="11"}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="plugins.generic.webhook.linkAction.resend"}
</form>
