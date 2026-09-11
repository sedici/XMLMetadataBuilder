<?php

namespace APP\plugins\generic\XMLMetadataBuilder\classes\components;

use PKP\components\forms\FormComponent;
use PKP\components\forms\FieldOptions;
use PKP\components\forms\FieldText;
use PKP\components\forms\FieldHTML;
use APP\plugins\generic\XMLMetadataBuilder\classes\services\EnrichmentService;

class EnrichmentForm extends FormComponent
{
    public $id = 'xmlEnricherForm';
    public $method = 'PUT';

    /**
     * Constructor
     *
     * @param string $action URL to submit the form to (REST API publication endpoint)
     * @param array $locales Supported form locales
     * @param array $xmlFiles Production-ready XML files
     * @param \PKP\publication\Publication|null $publication Current publication
     */
    public function __construct(string $action, array $locales, array $xmlFiles, $publication = null)
    {
        parent::__construct('xmlEnricherForm', 'PUT', $action, $locales);

        // Prepare options for XML file selection
        $xmlOptions = [];
        foreach ($xmlFiles as $file) {
            $fileName = $file->getLocalizedData('name');
            if (empty($fileName)) {
                $fileName = $file->getData('originalFileName') ?: ('File ' . $file->getId());
            }
            $xmlOptions[] = [
                'value' => (int) $file->getId(),
                'label' => $fileName,
            ];
        }

        $selectedFileId = '';
        // Always start empty so user explicitly chooses which XML to process

        // 1. XML File Selection
        if (!empty($xmlOptions)) {
            $this->addField(new FieldOptions(EnrichmentService::SETTING_XML_FILE_ID, [
                'label' => __('plugins.generic.XMLMetadataBuilder.selectXmlFile'),
                'description' => __('plugins.generic.XMLMetadataBuilder.selectXmlFile.description'),
                'type' => 'radio',
                'options' => $xmlOptions,
                'value' => $selectedFileId,
                'isMultilingual' => false,
                'isRequired' => true,
                'groupId' => 'default',
            ]));
        } else {
            $this->addField(new FieldHTML('noXmlFilesWarning', [
                'description' => '<div class="pkpNotification pkpNotification--warning">' . __('plugins.generic.XMLMetadataBuilder.noXmlFiles') . '</div>',
                'groupId' => 'default',
            ]));
        }

        // 2. Overwrite Checkbox
        $isOverwrite = false;
        if ($publication && $publication->getData(EnrichmentService::SETTING_OVERWRITE) !== null) {
            $isOverwrite = (bool) $publication->getData(EnrichmentService::SETTING_OVERWRITE);
        } elseif ($publication && $publication->getData(EnrichmentService::SETTING_FILE_ACTION) === EnrichmentService::FILE_ACTION_OVERWRITE) {
            $isOverwrite = true;
        }

        $this->addField(new FieldOptions(EnrichmentService::SETTING_OVERWRITE, [
            'label' => __('plugins.generic.XMLMetadataBuilder.overwrite'),
            'type' => 'checkbox',
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.generic.XMLMetadataBuilder.overwrite.confirm'),
                ]
            ],
            'value' => $isOverwrite,
            'groupId' => 'default',
        ]));

        // 3. Suffix field - option to customize suffix when generating a new production file
        $currentSuffix = $publication ? $publication->getData(EnrichmentService::SETTING_SUFFIX) : null;
        $this->addField(new FieldText(EnrichmentService::SETTING_SUFFIX, [
            'label' => __('plugins.generic.XMLMetadataBuilder.suffix'),
            'description' => __('plugins.generic.XMLMetadataBuilder.suffixDescription'),
            'value' => $currentSuffix ?: EnrichmentService::DEFAULT_SUFFIX,
            'isMultilingual' => false,
            'isRequired' => false,
            'groupId' => 'default',
        ]));

        // 4. Create Galley Checkbox
        $createGalleyValue = true;
        if ($publication && $publication->getData(EnrichmentService::SETTING_CREATE_GALLEY) !== null) {
            $createGalleyValue = (bool) $publication->getData(EnrichmentService::SETTING_CREATE_GALLEY);
        }

        $this->addField(new FieldOptions(EnrichmentService::SETTING_CREATE_GALLEY, [
            'label' => __('plugins.generic.XMLMetadataBuilder.createGalley'),
            'type' => 'checkbox',
            'options' => [
                [
                    'value' => true,
                    'label' => __('plugins.generic.XMLMetadataBuilder.createGalley.confirm'),
                ]
            ],
            'value' => $createGalleyValue,
            'groupId' => 'default',
        ]));

        // Set default group
        $this->addGroup([
            'id' => 'default',
            'pageId' => 'default',
        ]);

        // Set default page with customized submit button label
        $this->addPage([
            'id' => 'default',
            'submitButton' => [
                'label' => __('plugins.generic.XMLMetadataBuilder.publication.jats.generate'),
            ],
        ]);
    }
}
