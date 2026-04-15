<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Console\Command;

use Magento\Framework\Console\Cli;
use Magento\Framework\Exception\LocalizedException;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;
use Magento\PageBuilder\Model\TemplateRepository;
use MageOS\PageBuilderTemplateImportExport\Helper\Aliases as TemplateAliasHelper;
use MageOS\PageBuilderTemplateImportExport\Api\TemplateManagementInterface;

class ExportTemplate extends \Symfony\Component\Console\Command\Command
{
    const TEMPLATE_ID = 'id';
    const EXPORT_PATH = 'destination';
    const EXPORT_NAME = 'templateName';
    const TEMPLATE_THEME_TAGS = 'supportedThemes';
    const TEMPLATE_DESCRIPTION_TAG = 'description';

    /**
     * @param TemplateRepository $templateRepository
     * @param TemplateManagementInterface $templateManagement
     */
    public function __construct(
        protected TemplateRepository          $templateRepository,
        protected TemplateManagementInterface $templateManagement
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $options = [
            new InputOption(
                self::TEMPLATE_ID,
                null,
                InputOption::VALUE_REQUIRED,
                'PageBuilder template ID'
            ),
            new InputOption(
                self::EXPORT_PATH,
                null,
                InputOption::VALUE_REQUIRED,
                'Template export path'
            ),
            new InputOption(
                self::EXPORT_NAME,
                null,
                InputOption::VALUE_OPTIONAL,
                'Template export name'
            ),
            new InputOption(
                self::TEMPLATE_THEME_TAGS,
                null,
                InputOption::VALUE_OPTIONAL,
                'Themes supported by template (sed for remote templates only)'
            ),
            new InputOption(
                self::TEMPLATE_DESCRIPTION_TAG,
                null,
                InputOption::VALUE_OPTIONAL,
                'Description template tag (used for remote templates only)'
            ),
        ];
        $this->setName('mage-os:pagebuilder_template:export');
        $this->setDefinition($options);
        $this->setDescription('Export PageBuilder Template.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $output->setDecorated(true);

        if ($templateId = $input->getOption(self::TEMPLATE_ID)) {
            try {
                $template = $this->templateRepository->get($templateId);
            } catch (LocalizedException $e) {
                $output->writeln("Template not found");
            }

            if ($exportPath = $input->getOption(self::EXPORT_PATH) ?? 'tmp') {
                try {
                    $exportFile = $input->getOption(self::EXPORT_NAME) ?
                        $input->getOption(self::EXPORT_NAME) . ".zip" :
                        TemplateAliasHelper::DEFAULT_TEMPLATE_ARCHIVE_FILENAME;
                    $config = [
                        "name" => $template->getName(),
                        "type" => $template->getCreatedFor(),
                        "description" => $input->getOption(self::TEMPLATE_DESCRIPTION_TAG),
                        "themes" => $input->getOption(self::TEMPLATE_THEME_TAGS)
                    ];
                    $exportedArchivePath = $this->templateManagement->exportTemplate(
                        $exportFile,
                        $exportPath,
                        $template,
                        $config
                    );
                } catch (\Exception $e) {
                    $output->writeln("An error occurred generating template export file");
                    return Cli::RETURN_FAILURE;
                }
            } else {
                $output->writeln("Export path missing");
                return Cli::RETURN_FAILURE;
            }
        } else {
            $output->writeln("Template ID missing");
            return Cli::RETURN_FAILURE;
        }
        $output->writeln("Template exported correctly to " . $exportedArchivePath);
        return Cli::RETURN_SUCCESS;
    }
}
