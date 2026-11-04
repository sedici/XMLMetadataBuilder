{**
 * templates/settingsForm.tpl
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * Ajustes de XML Metadata Builder
 *}
<script>
	$(function() {ldelim}
		$('#xmlMetadataBuilderSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>

<form class="pkp_form" id="xmlMetadataBuilderSettingsForm" method="post" action="{url router=PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{include file="controllers/notification/inPlaceNotification.tpl" notificationId="xmlMetadataBuilderSettingsFormNotification"}

	{fbvFormArea id="xmlMetadataBuilderSettingsFormArea"}
		{fbvFormSection list=true title="plugins.generic.XMLMetadataBuilder.settings.galley"}
			{fbvElement type="checkbox" id="createXmlGalley" name="createXmlGalley" value="1" checked=$createXmlGalley label="plugins.generic.XMLMetadataBuilder.settings.createXmlGalley"}
		{/fbvFormSection}
	{/fbvFormArea}

	{fbvFormButtons}
</form>
