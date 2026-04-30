<?php
namespace APP\plugins\generic\XMLMetadataBuilder\classes\components;

use PKP\components\forms\FormComponent;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;

class EnrichmentForm extends FormComponent
{
    public $id = 'xmlEnricherForm';
    public $method = 'PUT';

    public function __construct($action, $locales, $xmlFiles, $publication = null)
    {
        parent::__construct('xmlEnricherForm', 'PUT', $action, $locales);

        // Prepare options for XML file selection
        $xmlOptions = [];
        foreach ($xmlFiles as $file) {
            $xmlOptions[] = [
                'value' => $file->getId(),
                'label' => $file->getLocalizedData('name'),
            ];
        }

        // Add field for XML file selection (radio buttons)
        if (!empty($xmlOptions)) {
            $this->addField(new FieldOptions(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_XML_FILE_ID, [
                'label' => __('plugins.generic.XMLMetadataBuilder.selectXmlFile'),
                'description' => __('plugins.generic.XMLMetadataBuilder.selectXmlFile.description'),
                'type' => 'radio',
                'options' => $xmlOptions,
                'value' => [],  // Always start empty, don't remember previous selection
                'isMultilingual' => false,
                'isRequired' => true,
                'groupId' => 'default',
            ]));
        }

        // Add checkbox for overwrite (SECOND)
        $this->addField(new FieldOptions(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_OVERWRITE, [
            'label' => __('plugins.generic.XMLMetadataBuilder.overwrite'),
            'type' => 'checkbox',
            'options' => [
                ['value' => true, 'label' => __('plugins.generic.XMLMetadataBuilder.overwrite.confirm')]
            ],
            'value' => $publication ? $publication->getData(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_OVERWRITE) : false,
            'groupId' => 'default',
        ]));

        // Add field for suffix (THIRD) - read current value from publication
        $currentSuffix = $publication ? $publication->getData(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_SUFFIX) : null;
        $this->addField(new FieldText(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_SUFFIX, [
            'label' => __('plugins.generic.XMLMetadataBuilder.suffix'),
            'description' => __('plugins.generic.XMLMetadataBuilder.suffixDescription'),
            'value' => $currentSuffix ?: \APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::DEFAULT_SUFFIX,  // Use saved value or default
            'isMultilingual' => false,
            'isRequired' => false,
            'groupId' => 'default',
        ]));

        // Add checkbox for createGalley (FOURTH)
        $this->addField(new FieldOptions(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_CREATE_GALLEY, [
            'label' => __('plugins.generic.XMLMetadataBuilder.createGalley'),
            'type' => 'checkbox',
            'options' => [
                ['value' => true, 'label' => __('plugins.generic.XMLMetadataBuilder.createGalley.confirm')]
            ],
            'value' => $publication ? $publication->getData(\APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService::SETTING_CREATE_GALLEY) : true, // Default to true
            'groupId' => 'default',
        ]));

        // Set groups
        $this->addGroup([
            'id' => 'default',
            'pageId' => 'default',
        ]);
        
        // Set pages
        $this->addPage([
            'id' => 'default',
            'submitButton' => [
                'label' => __('plugins.generic.XMLMetadataBuilder.publication.jats.generate'),
            ],
        ]);
    }
}
