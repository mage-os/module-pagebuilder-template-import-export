<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Controller\Adminhtml\Template\Remote;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use MageOS\PageBuilderTemplateImportExport\Service\Dropbox;
use Magento\Framework\Filesystem;
use MageOS\PageBuilderTemplateImportExport\Api\TemplateManagementInterface;
use MageOS\PageBuilderTemplateImportExport\Api\RemoteTemplateRepositoryInterface;
use MageOS\PageBuilderTemplateImportExport\Helper\ModuleConfig;
use PHPUnit\Util\Exception;
use Psr\Log\LoggerInterface;

class Import extends Action implements HttpPostActionInterface
{

    public const ADMIN_RESOURCE = 'MageOS_PageBuilderTemplateImportExport::pagebuilder_template_import';

    /**
     * @param LoggerInterface $logger
     * @param TemplateManagementInterface $templateManagement
     * @param Filesystem $filesystem
     * @param RemoteTemplateRepositoryInterface $remoteTemplateRepository
     * @param Dropbox $dropbox
     * @param ModuleConfig $moduleConfig
     * @param Context $context
     */
    public function __construct(
        protected LoggerInterface $logger,
        protected TemplateManagementInterface $templateManagement,
        protected Filesystem $filesystem,
        protected RemoteTemplateRepositoryInterface $remoteTemplateRepository,
        protected Dropbox $dropbox,
        protected ModuleConfig $moduleConfig,
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * Import template
     *
     * @inheritDoc
     */
    public function execute()
    {
        $resultRedirect = $this->resultRedirectFactory->create();
        $request = $this->getRequest();

        try {
            $remoteTemplate = $this->remoteTemplateRepository->getById((int)$request->getParam('entity_id'));

            $credentials = $this->moduleConfig->getDropboxAccountCredentialsByAppKey(
                $remoteTemplate->getData("remote_storage_id")
            );
            if ($credentials === false) {
                throw new Exception("Remote storage not found.");
            }
            $importExportPath = $this->filesystem
                ->getDirectoryRead(DirectoryList::VAR_IMPORT_EXPORT)
                ->getAbsolutePath();
            $tmpTemplateDownloadPath = $importExportPath . '/tmp-template.zip';
            $this->dropbox->downloadZip(
                $remoteTemplate->getData("file_path"),
                $tmpTemplateDownloadPath,
                $credentials["app_key"],
                $credentials["app_secret"],
                $credentials["refresh_token"],
            );
            $importedTemplate = $this->templateManagement
                ->importTemplateFromArchive($tmpTemplateDownloadPath, $remoteTemplate["file_path"]);
            $importedTemplateId = $importedTemplate->getId();
            $externalUrls = $this->templateManagement->doSecurityScanForTemplate($importedTemplate->getTemplate());

            if ($importedTemplateId) {
                if (!empty($externalUrls)) {
                    $this->messageManager->addSuccessMessage(
                        __(
                            "Template with ID %1 correctly imported. Please verify the security of these external resources in the template: %2",
                            $importedTemplateId,
                            implode(', ', $externalUrls)
                        )
                    );
                } else {
                    $this->messageManager->addSuccessMessage(
                        __("Template with ID %1 correctly imported.", $importedTemplateId)
                    );
                }
            }
        } catch (\Exception $e) {
            $this->logger->error($e);
            $this->messageManager->addErrorMessage(
                __("An error occurred downloading the selected template. Check the logs or try again later.")
            );
            return $resultRedirect->setPath('pagebuilder/template/index');
        }
        return $resultRedirect->setPath('pagebuilder/template/index');
    }
}
