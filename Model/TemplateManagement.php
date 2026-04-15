<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Serialize\SerializerInterface;
use MageOS\PageBuilderTemplateImportExport\Api\TemplateManagementInterface;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\DB\DataConverter\DataConversionException;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\InputException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem\Directory\WriteInterface;
use Magento\PageBuilder\Api\Data\TemplateInterface;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Framework\Data\Wysiwyg\Normalizer;
use MageOS\PageBuilderTemplateImportExport\Helper\Aliases as TemplateAliasHelper;
use Magento\PageBuilder\Model\TemplateRepository;
use MageOS\PageBuilderTemplateImportExport\DataConverter\CmsConverter;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Io\File;
use Magento\Framework\Filesystem\Driver\File as FileDriver;
use Magento\Framework\Api\ImageContentFactory;
use Magento\Framework\Api\ImageContentValidator;
use Magento\PageBuilder\Model\TemplateFactory;
use Magento\Framework\Image\AdapterFactory;
use Magento\MediaStorage\Helper\File\Storage\Database;
use Magento\Framework\Convert\ConvertArray;
use Magento\Cms\Model\BlockFactory;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Framework\Xml\Parser as XmlParser;
use ZipArchive;
use FilesystemIterator;
use Exception;

class TemplateManagement implements TemplateManagementInterface
{

    const EXTERNAL_URL_WHITELIST = [
        'http://www.w3.org/2000/svg'
    ];

    /**
     * @param CmsConverter $cmsConverter
     * @param Filesystem $filesystem
     * @param File $fileIo
     * @param FileDriver $fileDriver
     * @param StoreManagerInterface $storeManager
     * @param TemplateRepository $templateRepository
     * @param TemplateFactory $templateFactory
     * @param Normalizer $wysiswygNormalizer
     * @param AdapterFactory $imageAdapterFactory
     * @param ImageContentFactory $imageContentFactory
     * @param Database $mediaStorage
     * @param ImageContentValidator $imageContentValidator
     * @param ConvertArray $convertArray
     * @param BlockRepositoryInterface $blockRepository
     * @param BlockFactory $blockFactory
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param XmlParser $xmlParser
     * @param SerializerInterface $serializer
     * @param ScopeConfigInterface $scopeConfig
     * @param DeploymentConfig $deploymentConfig
     */
    public function __construct(
        protected CmsConverter $cmsConverter,
        protected File $fileIo,
        protected Filesystem $filesystem,
        protected FileDriver $fileDriver,
        protected StoreManagerInterface $storeManager,
        protected TemplateRepository $templateRepository,
        protected TemplateFactory $templateFactory,
        protected Normalizer $wysiswygNormalizer,
        protected AdapterFactory $imageAdapterFactory,
        protected ImageContentFactory $imageContentFactory,
        protected Database $mediaStorage,
        protected ImageContentValidator $imageContentValidator,
        protected ConvertArray $convertArray,
        protected BlockRepositoryInterface $blockRepository,
        protected BlockFactory $blockFactory,
        protected SearchCriteriaBuilder $searchCriteriaBuilder,
        protected XmlParser $xmlParser,
        protected SerializerInterface $serializer,
        protected ScopeConfigInterface $scopeConfig,
        protected DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Copy exported archive assets files inside pub/media folder
     *
     * @param string $sourcePath
     * @param string|null $destinationPath
     * @return array
     */
    protected function copyAssetsFilesToMediaDirectory(string $sourcePath, ?string $destinationPath = null): array
    {
        $exceptionMessages = [];
        if (!is_dir($sourcePath)) {
            return [];
        }

        if (!$destinationPath) {
            $destinationPath = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath();
        }
        $flags = FilesystemIterator::SKIP_DOTS | FilesystemIterator::UNIX_PATHS;
        $iterator = new FilesystemIterator($sourcePath, $flags);
        /** @var FilesystemIterator $entity */

        foreach ($iterator as $entity) {
            try {
                if ($entity->isDir()) {
                    $fileName = $entity->getFilename();
                    if (substr($fileName, -1) !== "/") {
                        $fileName = $fileName . "/";
                    }
                    $exceptionMessages = array_merge(
                        $exceptionMessages,
                        $this->copyAssetsFilesToMediaDirectory(
                            $entity->getPathname(),
                            $destinationPath . $fileName
                        )
                    );
                } else {
                    if (substr($destinationPath, -1) !== "/") {
                        $destinationPath = $destinationPath . "/";
                    }
                    if (!$this->fileIo->fileExists($destinationPath, false)) {
                        $this->fileIo->mkdir($destinationPath);
                    }
                    $this->fileDriver->copy($entity->getPathname(), $destinationPath . $entity->getFilename());
                }
            } catch (FileSystemException $exception) {
                $exceptionMessages[] = $exception->getMessage();
            }
        }
        return $exceptionMessages;
    }

    /**
     * Store template preview image
     *
     * @param $preview
     * @return string|null
     * @throws FileSystemException
     * @throws LocalizedException
     * @throws InputException
     */
    protected function storePreviewImage($preview): ?string
    {
        $fileName = preg_replace(
                "/[^A-Za-z0-9]/",
                '',
                str_replace(' ', '-', "import")
            ) . uniqid() . '.jpg';

        // phpcs:ignore
        $decodedImage = $preview;

        $imageProperties = getimagesizefromstring($decodedImage);

        if (!$imageProperties) {
            throw new LocalizedException(__('Unable to get properties from image.'));
        }

        $imageContent = $this->imageContentFactory->create();
        $imageContent->setBase64EncodedData(base64_encode($preview));
        $imageContent->setName($fileName);
        $imageContent->setType($imageProperties['mime']);

        if ($this->imageContentValidator->isValid($imageContent)) {
            $mediaDirWrite = $this->filesystem
                ->getDirectoryWrite(DirectoryList::MEDIA);
            $directory = $mediaDirWrite->getAbsolutePath('.template-manager');
            $mediaDirWrite->create($directory);
            if (substr($fileName, 0, 1) !== "/") {
                $fileName = "/" . $fileName;
            }
            $fileAbsolutePath = $directory . $fileName;
            // Write the file to the directory
            $mediaDirWrite->getDriver()->filePutContents($fileAbsolutePath, $decodedImage);
            // Generate a thumbnail, called -thumb next to the image for usage in the grid
            $thumbPath = str_replace('.jpg', '-thumb.jpg', $fileName);
            $thumbAbsolutePath = $directory . $thumbPath;
            $imageFactory = $this->imageAdapterFactory->create();
            $imageFactory->open($fileAbsolutePath);
            $imageFactory->resize(350);

            try {
                $imageFactory->save($thumbAbsolutePath);
            } catch (Exception $e) {
                return null;
            }

            $this->mediaStorage->saveFile($fileAbsolutePath);
            $this->mediaStorage->saveFile($thumbAbsolutePath);
            // Store the preview image within the new entity
            return $mediaDirWrite->getRelativePath($fileAbsolutePath);
        }

        return null;
    }

    /**
     *
     * @param TemplateInterface $template
     * @return array
     * @throws DataConversionException
     */
    private function convertTemplateHtml(TemplateInterface $template): array
    {
        return $this->cmsConverter->convert($template->getTemplate());
    }

    /**
     * @param string $exportFile
     * @return array
     * @throws FileSystemException
     */
    public function openExportArchive(string $exportFile): array
    {
        $writer = $this->filesystem->getDirectoryWrite(DirectoryList::VAR_EXPORT);
        $exportDestination = $writer->getAbsolutePath() . $exportFile;
        $zip = new ZipArchive();
        $zip->open($exportDestination, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        return [$zip, $writer];
    }

    /**
     * @param string $path
     * @return void
     * @throws FileSystemException
     */
    public function deleteTmpFolder(string $path): void
    {
        $path = $this->filesystem->getDirectoryRead(DirectoryList::VAR_EXPORT)->getAbsolutePath() . $path;
        $this->fileDriver->deleteDirectory($path);
    }

    /**
     * @param ZipArchive $zip
     * @return void
     */
    public function closeExportArchive(ZipArchive $zip): void
    {
        $zip->close();
    }

    /**
     * @param WriteInterface $writer
     * @param ZipArchive $zip
     * @param TemplateInterface $template
     * @param string $exportPath
     * @return void
     * @throws DataConversionException|FileSystemException
     */
    public function generateTemplateFileAndRelativeAssets(
        WriteInterface $writer,
        ZipArchive $zip,
        TemplateInterface $template,
        string $exportPath
    ): void {
        $convertedTemplate = $this->convertTemplateHtml($template);
        $exportName = TemplateAliasHelper::TEMPLATE_FILE;
        $templateFile = $writer->openFile($exportPath . "/" . $exportName, 'w');

        try {
            $templateFile->lock();
            try {
                $templateFile->write($convertedTemplate["value"]);
            } finally {
                $templateFile->unlock();
            }
        } finally {
            $templateFile->close();
            $zip->addFile($writer->getAbsolutePath() . $exportPath . "/" . $exportName, $exportName);
        }

        foreach ($convertedTemplate["assets"] as $asset) {
            $reader = $this->filesystem->getDirectoryRead(DirectoryList::PUB);
            $zip->addFile(
                $reader->getAbsolutePath() . $asset,
                TemplateAliasHelper::ASSETS_FOLDER_NAME . "/" . $asset
            );
        }

        foreach ($convertedTemplate["children"] as $childName => $child) {
            $exportName = $childName .
                TemplateAliasHelper::CHILD_NAME_PARAM_SEPARATOR .
                $child["block_id"] .
                TemplateAliasHelper::CHILD_NAME_PARAM_SEPARATOR .
                $child["order"] .
                ".html";
            $exportPath .= "/" . TemplateAliasHelper::CHILDREN_FOLDER_NAME . "/";
            $templateFile = $writer->openFile($exportPath . $exportName, 'w');

            try {
                $templateFile->lock();

                try {
                    $templateFile->write($child["content"]);
                } finally {
                    $templateFile->unlock();
                }
            } finally {
                $templateFile->close();
                $zip->addFile(
                    $writer->getAbsolutePath() . $exportPath . $exportName,
                    TemplateAliasHelper::CHILDREN_FOLDER_NAME . "/" . $exportName
                );
            }
        }
    }

    /**
     * @param WriteInterface $writer
     * @param ZipArchive $zip
     * @param TemplateInterface $template
     * @param string $exportPath
     * @return void
     */
    public function generateTemplatePreviewFile(
        WriteInterface $writer,
        ZipArchive $zip,
        TemplateInterface $template,
        string $exportPath
    ): void {
        $previewFile = $this->filesystem
                ->getDirectoryRead(DirectoryList::MEDIA)
                ->getAbsolutePath() . $template->getPreviewImage();
        $zip->addFile($previewFile, TemplateAliasHelper::PREVIEW_FILE);
    }

    /**
     * @param WriteInterface $writer
     * @param ZipArchive $zip
     * @param array $configXml
     * @param string $exportPath
     * @return void
     * @throws FileSystemException|LocalizedException
     */
    public function generateConfigFile(
        WriteInterface $writer,
        ZipArchive $zip,
        array $configXml,
        string $exportPath
    ): void {
        $exportName = TemplateAliasHelper::CONFIG_FILE;
        $simpleXmlContents = $this->convertArray->assocToXml($configXml, "config");
        $configXml = $simpleXmlContents->asXML();
        $configFile = $writer->openFile($exportPath . "/" . $exportName, 'w');

        try {
            $configFile->lock();

            try {
                $configFile->write($configXml);
            } finally {
                $configFile->unlock();
            }
        } finally {
            $configFile->close();
            $zip->addFile(
                $writer->getAbsolutePath() . $exportPath . "/" . $exportName,
                TemplateAliasHelper::CONFIG_FILE
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function exportTemplate(
        string $exportFile,
        string $exportPath,
        TemplateInterface $template,
        array $config
    ): string {
        /**
         * @var ZipArchive $zip
         * @var WriteInterface $writer
         */
        list($zip, $writer) = $this->openExportArchive($exportFile);

        $this->generateTemplateFileAndRelativeAssets($writer, $zip, $template, $exportPath);
        $this->generateTemplatePreviewFile($writer, $zip, $template, $exportPath);
        $this->generateConfigFile($writer, $zip, $config, $exportPath);
        $this->closeExportArchive($zip);
        $this->deleteTmpFolder($exportPath);

        $file = $writer->getAbsolutePath() . $exportFile;

        return $file;
    }

    /**
     * @param string $importPath
     * @param string $filePath
     * @return TemplateInterface|null
     * @throws FileSystemException
     * @throws InputException
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function importTemplateFromArchive(string $importPath, string $filePath = ""): ?TemplateInterface
    {
        $reader = $this->filesystem->getDirectoryRead(DirectoryList::VAR_EXPORT);
        $zip = new ZipArchive();
        $zip->open($importPath);

        $tmpFolder = $reader->getAbsolutePath() . "tmp";
        $zip->extractTo($tmpFolder);
        $zip->close();
        if (substr($filePath, 0, 1) !== "/") {
            $filePath = "/" . $filePath;
        }
        $templateHtmlContent = $reader->readFile($tmpFolder . $filePath . "/" . TemplateAliasHelper::TEMPLATE_FILE);

        $baseUrl = trim($this->storeManager->getStore()->getBaseUrl(), "/");
        $baseUrl = $this->wysiswygNormalizer->replaceReservedCharacters($baseUrl);
        $templateHtmlContent = str_replace(
            TemplateAliasHelper::CMS_WIDGET_URL_PLACEHOLDER,
            $baseUrl,
            $templateHtmlContent
        );
        $previewFileName = $this->storePreviewImage(
            $reader->readFile($tmpFolder . $filePath . "/" . TemplateAliasHelper::PREVIEW_FILE)
        );
        $exceptionMessages = $this->copyAssetsFilesToMediaDirectory(
            $tmpFolder . $filePath . "/" . TemplateAliasHelper::ASSETS_FOLDER_NAME . "/media/",
            $this->filesystem->getDirectoryRead(DirectoryList::MEDIA)->getAbsolutePath()
        );
        $childrenImportResult = $this->importTemplateChildren(
            $tmpFolder . $filePath . "/" . TemplateAliasHelper::CHILDREN_FOLDER_NAME
        );
        $exceptionMessages = array_merge(
            $exceptionMessages,
            $childrenImportResult["exceptions"] ?? []
        );

        $templateHtmlContent = $this->substituteChildrenIds(
            $templateHtmlContent,
            $childrenImportResult["children"] ?? []
        );

        $templateHtmlContent = $this->substituteAdminhtmlStaticUrl($templateHtmlContent);

        if (empty($exceptionMessages)) {
            try {
                $config = $this->xmlParser
                    ->load($tmpFolder . $filePath . "/" . TemplateAliasHelper::CONFIG_FILE)
                    ->xmlToArray()["config"];
                $template = $this->templateFactory->create();
                $template->setName($config["name"]);
                $template->setTemplate($templateHtmlContent);
                $template->setCreatedFor($config["type"]);
                $template->setPreviewImage($previewFileName);
                $importedTemplate = $this->templateRepository->save($template);

                $this->fileDriver->deleteDirectory($tmpFolder);
            } catch (Exception $e) {
                throw new Exception("An error occurred saving template");
            }
        } else {
            throw new Exception("An error occurred saving template dependencies");
        }

        if ($importedTemplate && $importedTemplate->getId()) {
            return $importedTemplate;
        }
        return null;
    }

    /**
     * Replace the adminhtml static content URL placeholder with the actual
     * pub/static URL of the destination site's admin area.
     *
     * Reconstructs the URL from the store base URL, the configured admin
     * frontName, and the currently deployed static content version.
     *
     * @param string $content
     * @return string
     */
    protected function substituteAdminhtmlStaticUrl(string $content): string
    {
        $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        $adminFrontName = $this->deploymentConfig->get('backend/frontName', 'admin');

        $pubPath = $this->filesystem->getDirectoryRead(DirectoryList::PUB)->getAbsolutePath();
        $deployedVersionFile = rtrim($pubPath, '/') . '/static/deployed_version.txt';
        $versionSegment = '';
        if (file_exists($deployedVersionFile)) {
            $versionSegment = 'version' . trim(file_get_contents($deployedVersionFile)) . '/';
        }

        $adminStaticUrl = $baseUrl . '/static/' . $versionSegment . 'adminhtml/';

        return str_replace(
            TemplateAliasHelper::ADMINHTML_STATIC_CONTENT_URL_PLACEHOLDER,
            $adminStaticUrl,
            $content
        );
    }

    /**
     * Import template's children cms blocks
     *
     * @param string $childrenFolderPath
     * @return array
     */
    private function importTemplateChildren(string $childrenFolderPath): array
    {
        $childNameSeparator = TemplateAliasHelper::CHILD_NAME_PARAM_SEPARATOR;
        $exceptionMessages = [];

        // Check if the folder exists
        if (!is_dir($childrenFolderPath)) {
            return $exceptionMessages;
        }

        // Scan the folder
        $files = scandir($childrenFolderPath);

        foreach ($files as $file) {
            // Skip non-files
            if (is_dir($childrenFolderPath . '/' . $file)) {
                continue;
            }

            // Match the filename pattern
            if (preg_match("/^(.*?)$childNameSeparator(.*?)$childNameSeparator(\d+)\.html$/", $file, $matches)) {
                $prefix = $matches[1];
                $id = $matches[2];
                $order = (int)$matches[3];
                $content = file_get_contents($childrenFolderPath . '/' . $file);
                $children[$order] = [
                    'id' => $id,
                    'name' => $prefix,
                    'content' => $content
                ];
            }
        }

        // Sort the array by order key
        usort($children, function ($a, $b) {
            return key($a) <=> key($b);
        });

        foreach ($children as $order => $childCmsBlock) {
            $childCmsBlock["content"] = $this->substituteChildrenIds($childCmsBlock["content"], $children);
            $suffix = 1;

            while (true) {
                try {
                    if (!$this->cmsBlockIdentifierAlreadyExists($childCmsBlock["name"])) {
                        break;
                    }
                    $childCmsBlock["name"] = $childCmsBlock["name"] . "_" . $suffix;
                    $suffix++;
                } catch (Exception $e) {
                    break;
                }
            }

            $blockData = [
                "content" => $childCmsBlock["content"],
                "identifier" => $childCmsBlock["name"],
                "title" => $childCmsBlock["name"]
            ];

            try {
                $cmsBlock = $this->blockFactory->create(['data' => $blockData]);
                $cmsBlock = $this->blockRepository->save($cmsBlock);
                $children[$order]["imported_id"] = $cmsBlock->getId();
            } catch (Exception $e) {
                $exceptionMessages[] = $e->getMessage();
            }
        }

        return ["exceptions" => $exceptionMessages, "children" => $children];
    }

    /**
     * Check if a CMS block with the given identifier exists.
     *
     * @param string $identifier
     * @return bool
     * @throws LocalizedException
     */
    protected function cmsBlockIdentifierAlreadyExists(string $identifier): bool
    {
        $searchCriteria = $this->searchCriteriaBuilder
            ->addFilter('identifier', $identifier, 'eq')
            ->create();
        $blocks = $this->blockRepository->getList($searchCriteria)->getItems();

        return !empty($blocks);
    }

    /**
     * Substitute new cms block ids inside template content.
     *
     * @param string $content
     * @param array $children
     * @return string
     */
    protected function substituteChildrenIds(string $content, array $children): string
    {
        foreach ($children as $key => $child) {
            if (isset($child["imported_id"])) {
                $content = str_replace(
                    "block_id=\"" . $child["id"] . "\"",
                    "block_id=\"" . $child["imported_id"] . "\"",
                    $content
                );
            }
        }

        return $content;
    }

    /**
     * @param string $templateHtml
     * @return array
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    public function doSecurityScanForTemplate(string $templateHtml): array
    {
        $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        $parsedBase = parse_url($baseUrl);
        $host = $parsedBase['host'];
        if (isset($parsedBase['port'])) {
            $host .= ':' . $parsedBase['port'];
        }

        preg_match_all('#(?:https://|http://)[^"\'\s>)]+#i', $templateHtml, $urlMatches);

        // Sanitize each raw match: strip everything from the first character
        // that cannot be part of a URL. This handles JSON-escaped quotes (\"),
        // HTML-entity-encoded delimiters (&quot; &gt;) and stray HTML tags
        // that are captured when the source content is encoded.
        $foundUrls = array_values(array_unique(array_filter(array_map(
            static function (string $raw): string {
                // Stop at backslash (JSON escape), actual quote/whitespace/angle-bracket
                $clean = preg_replace('/(?:["\'\s<>\\\\]|&(?:quot|gt|lt|amp|apos);).*/s', '', $raw);
                return $clean ?? '';
            },
            $urlMatches[0]
        ))));

        $externalUrls = [];
        foreach ($foundUrls as $url) {
            $parsedUrl = parse_url($url);
            $urlHost = $parsedUrl['host'] ?? '';
            if (isset($parsedUrl['port'])) {
                $urlHost .= ':' . $parsedUrl['port'];
            }

            if ($urlHost === $host) {
                continue;
            }

            $whitelisted = false;
            foreach (self::EXTERNAL_URL_WHITELIST as $entry) {
                if (str_contains($url, $entry)) {
                    $whitelisted = true;
                    break;
                }
            }

            if (!$whitelisted) {
                $externalUrls[] = $url;
            }
        }

        return $externalUrls;
    }
}
