<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Controller\Adminhtml\Template;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Filesystem;
use Magento\Framework\File\UploaderFactory;
use Magento\Framework\HTTP\Adapter\FileTransferFactory;
use MageOS\PageBuilderTemplateImportExport\Api\TemplateManagementInterface;
use Psr\Log\LoggerInterface;

class Import extends Action implements HttpPostActionInterface
{

    public const ADMIN_RESOURCE = 'MageOS_PageBuilderTemplateImportExport::pagebuilder_template_import';

    /**
     * @param LoggerInterface $logger
     * @param TemplateManagementInterface $templateManagement
     * @param Filesystem $filesystem
     * @param UploaderFactory $uploaderFactory
     * @param FileTransferFactory $httpFactory
     * @param Context $context
     */
    public function __construct(
        private LoggerInterface $logger,
        private TemplateManagementInterface $templateManagement,
        private Filesystem $filesystem,
        private UploaderFactory $uploaderFactory,
        private FileTransferFactory $httpFactory,
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
        $request = $this->getRequest();
        $result = ['success' => true];

        try {
            $uploader = $this->httpFactory->create();
            $binary = $request->getParam('param_name', 'file_uploader');

            if ($uploader->isValid($binary)) {
                $destinationFolder = $this->filesystem
                    ->getDirectoryRead(DirectoryList::VAR_EXPORT)->getAbsolutePath();

                $uploader = $this->uploaderFactory->create(['fileId' => $binary]);
                $uploader->setFilesDispersion(false);
                $uploader->setAllowRenameFiles(true);
                $result = $uploader->save($destinationFolder);

                $importedFilePath = $result['path'] . $result['file'];
                $template = $this->templateManagement->importTemplateFromArchive($importedFilePath);
                $externalUrls = $this->templateManagement->doSecurityScanForTemplate($template->getTemplate());
                $result['external_urls'] = implode(',', $externalUrls);
            }

        } catch (\Exception $e) {
            $result = [
                'success' => false,
                'error' => $e->getMessage(),
                'errorcode' => $e->getCode()
            ];
            $this->logger->error($e);
        }

        return $this->resultFactory->create(ResultFactory::TYPE_JSON)->setData($result);
    }
}
