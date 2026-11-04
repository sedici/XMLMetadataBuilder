<?php

/**
 * @file plugins/generic/XMLMetadataBuilder/XMLMetadataBuilderSettingsForm.php
 *
 * Distributed under the GNU GPL v3. For full terms see the file LICENSE.
 *
 * @class XMLMetadataBuilderSettingsForm
 *
 * @brief Ajustes del plugin por revista.
 */

namespace APP\plugins\generic\XMLMetadataBuilder;

use APP\template\TemplateManager;
use PKP\form\Form;
use PKP\form\validation\FormValidatorCSRF;
use PKP\form\validation\FormValidatorPost;

class XMLMetadataBuilderSettingsForm extends Form
{
    public function __construct(private XMLMetadataBuilderPlugin $plugin, private int $contextId)
    {
        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));

        $this->addCheck(new FormValidatorPost($this));
        $this->addCheck(new FormValidatorCSRF($this));
    }

    /**
     * @copydoc Form::initData()
     */
    public function initData(): void
    {
        $this->setData(
            XMLMetadataBuilderPlugin::SETTING_CREATE_XML_GALLEY,
            $this->plugin->isXmlGalleyEnabled($this->contextId)
        );

        parent::initData();
    }

    /**
     * @copydoc Form::readInputData()
     */
    public function readInputData(): void
    {
        $this->readUserVars([XMLMetadataBuilderPlugin::SETTING_CREATE_XML_GALLEY]);
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false): string
    {
        TemplateManager::getManager($request)->assign('pluginName', $this->plugin->getName());

        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->plugin->updateSetting(
            $this->contextId,
            XMLMetadataBuilderPlugin::SETTING_CREATE_XML_GALLEY,
            (bool) $this->getData(XMLMetadataBuilderPlugin::SETTING_CREATE_XML_GALLEY),
            'bool'
        );

        parent::execute(...$functionArgs);
    }
}
