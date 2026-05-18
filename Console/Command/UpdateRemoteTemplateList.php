<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Console\Command;

use Magento\Framework\Console\Cli;
use MageOS\PageBuilderTemplateImportExport\Api\RemoteStorageManagementInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputOption;

class UpdateRemoteTemplateList extends \Symfony\Component\Console\Command\Command
{

    /**
     * @param RemoteStorageManagementInterface $remoteStorageManagement
     */
    public function __construct(
        protected RemoteStorageManagementInterface $remoteStorageManagement
    ) {
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('mage-os:pagebuilder_template:update-remote-list');
        $this->setDescription('Update PageBuilder remote template list.');
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $output->setDecorated(true);
        try {
            $this->remoteStorageManagement->updateRemoteTemplatesInformations(true);
        } catch (\Exception $e) {
            $output->writeln("An error occurred updating the remote template list: " . $e->getMessage());
            return Cli::RETURN_FAILURE;
        }
        return Cli::RETURN_SUCCESS;
    }
}
