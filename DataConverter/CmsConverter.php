<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\DataConverter;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Data\Wysiwyg\Normalizer;
use Magento\Framework\DB\DataConverter\DataConversionException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filesystem;
use Magento\Framework\Filesystem\Directory\ReadInterface;
use Magento\Framework\Filter\Template\Tokenizer\Parameter;
use Magento\Framework\Filter\Template\Tokenizer\ParameterFactory;
use Magento\Framework\DB\DataConverter\SerializedToJson;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Cms\Api\BlockRepositoryInterface;
use Magento\Store\Model\StoreManagerInterface;
use MageOS\PageBuilderTemplateImportExport\Helper\Aliases as TemplateAliasHelper;
use MageOS\PageBuilderTemplateImportExport\Model\PathValidator;

class CmsConverter extends SerializedToJson
{

    /**
     * @var array
     */
    protected $assets = [];

    /**
     * @var array
     */
    protected $cmsBlocks = [];

    /**
     * @var ReadInterface|null
     */
    private ?ReadInterface $mediaDirectory = null;

    /**
     * @param Normalizer $normalizer
     * @param ParameterFactory $parameterFactory
     * @param Json $json
     * @param BlockRepositoryInterface $cmsBlockRepository
     * @param Serialize $serialize
     * @param StoreManagerInterface $storeManager
     * @param ManagerInterface $messageManager
     * @param DeploymentConfig $deploymentConfig
     * @param PathValidator $pathValidator
     * @param Filesystem $filesystem
     */
    public function __construct(
        protected Normalizer $normalizer,
        protected ParameterFactory $parameterFactory,
        protected Json $json,
        protected BlockRepositoryInterface $cmsBlockRepository,
        protected Serialize $serialize,
        protected StoreManagerInterface $storeManager,
        protected ManagerInterface $messageManager,
        protected DeploymentConfig $deploymentConfig,
        protected PathValidator $pathValidator,
        protected Filesystem $filesystem
    ) {
        parent::__construct($serialize, $json);
    }

    /**
     * Convert template/cms block content extracting and converting children also
     *
     * @param $value
     * @param bool $child
     * @return array|string
     * @throws DataConversionException
     */
    public function convert($value, bool $child = false) : array|string
    {
        $convertedValue = '';
        //Convert and extract widgets media
        preg_match_all(
            '/(.*?){{widget(.*?)}}/si',
            $value,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $convertedValue .= $match[1] . '{{widget' . $this->convertWidgetParams($match[2]) . '}}';
        }
        preg_match_all(
            '/(.*?{{widget.*?}})*(?<ending>.*?)$/si',
            $value,
            $matchesTwo,
            PREG_SET_ORDER
        );
        if (isset($matchesTwo[0])) {
            $convertedValue .= $matchesTwo[0]['ending'];
        }

        //Convert and extract pageBuilder media
        preg_match_all(
            '/(.*?){{media(.*?)}}/si',
            $value,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            if (isset($match[2])) {
                $parts = explode("=", $match[2]);
                if (!isset($parts[1])) {
                    continue;
                }
                $url = trim($parts[1], " \t\n\r\0\x0B\"'");
                if (!$this->pathValidator->isSafeRelativePath($url)) {
                    $this->messageManager->addWarningMessage(
                        (string)__('Skipped template asset with unsafe media path: %1', $parts[1])
                    );
                    continue;
                }
                if (!in_array("/media/" . $url, $this->assets)) {
                    $this->assets[] = "/media/" . $url;
                }
            }
        }

        //Extract cms blocks needed by template
        preg_match_all(
            '/(.*?){{widget\s+type="Magento\\\\Cms\\\\Block\\\\Widget\\\\Block"(.*?)}}/si',
            $value,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $cmsBlock = $this->extractCmsBlock($match[2]);
            if (!array_key_exists($cmsBlock["identifier"], $this->cmsBlocks)) {
                $orderId = count($this->cmsBlocks);
                $this->cmsBlocks[$cmsBlock["identifier"]] = [
                    "block_id" => $cmsBlock["block_id"],
                    "content" => $cmsBlock["content"],
                    "order" => $orderId
                ];
            }
        }
        $this->substituteSiteUrls($convertedValue);

        if ($child) {
            return $convertedValue;
        }
        return ["value" => $convertedValue, "assets" => $this->assets, "children" => $this->cmsBlocks];
    }

    /**
     * The pub/media directory, resolved lazily since not every convert() call needs it.
     *
     * @return ReadInterface
     */
    private function getMediaDirectory(): ReadInterface
    {
        if ($this->mediaDirectory === null) {
            $this->mediaDirectory = $this->filesystem->getDirectoryRead(DirectoryList::MEDIA);
        }
        return $this->mediaDirectory;
    }

    /**
     * Register $value as a template asset when it resolves to a real file under pub/media.
     *
     * Widget image fields (the plain "Banner image" field as much as an item inside a
     * repeatable row) do not necessarily store an absolute URL - the stock media browser
     * dialog fills them in with a bare path relative to pub/media (e.g. "wysiwyg/x.jpg"),
     * the same convention {{media url="..."}} directives use. The previous asset scan only
     * ever looked inside repeatable_* / conditions_encoded parameters and only recognised a
     * full "https://host/media/..." URL, so a plain image parameter - or any image field
     * storing the bare relative form - was never added to the export archive at all, and
     * silently missing once re-imported on another environment. Checking the filesystem
     * instead of guessing from a naming convention or a URL shape catches every form
     * (absolute URL, root-relative "/media/...", or bare relative path) and works for any
     * widget's image field, not just the ones whose parameter names this converter knows.
     *
     * @param string $value
     * @return void
     */
    private function collectMediaAsset(string $value): void
    {
        $value = trim($value);
        if ($value === '' || strlen($value) > 2048) {
            return;
        }

        $candidate = $value;
        if (preg_match('#^https?://[^/\s]+(/.*)$#i', $value, $matches)) {
            $candidate = $matches[1];
        }
        $candidate = ltrim(str_replace('\\', '/', $candidate), '/');
        if (str_starts_with($candidate, 'media/')) {
            $candidate = substr($candidate, strlen('media/'));
        }

        if ($candidate === ''
            || !$this->pathValidator->isSafeRelativePath($candidate)
            || !$this->getMediaDirectory()->isFile($candidate)
        ) {
            return;
        }

        $asset = 'media/' . $candidate;
        if (!in_array($asset, $this->assets, true)) {
            $this->assets[] = $asset;
        }
    }

    /**
     * Whether an extracted asset path is a safe file under pub/media.
     *
     * Accepts a "media/"-rooted path (with or without a leading slash) whose
     * remainder is a plain relative path, matching the containment the export
     * file-access layer enforces. Absolute non-media paths, traversal, and
     * scheme/drive prefixes are rejected so nothing outside pub/media is
     * collected for export.
     *
     * @param string $path
     * @return bool
     */
    private function isCollectableMediaPath(string $path): bool
    {
        $normalized = ltrim(str_replace('\\', '/', $path), '/');
        if (!str_starts_with($normalized, 'media/')) {
            return false;
        }

        return $this->pathValidator->isSafeRelativePath(substr($normalized, strlen('media/')));
    }

    /**
     * @param string $convertedValue
     * @return void
     * @throws LocalizedException
     * @throws \Magento\Framework\Exception\NoSuchEntityException
     */
    protected function substituteSiteUrls(string &$convertedValue): void
    {
        $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
        $parsedBase = parse_url($baseUrl);
        $host = $parsedBase['host'] ?? '';
        if (isset($parsedBase['port'])) {
            $host .= ':' . $parsedBase['port'];
        }
        if ($host === '') {
            return;
        }

        $adminStaticPattern = '#https?://' . preg_quote($host, '#') . '/static/version\d+/adminhtml/#';
        $convertedValue = preg_replace(
            $adminStaticPattern,
            TemplateAliasHelper::ADMINHTML_STATIC_CONTENT_URL_PLACEHOLDER,
            $convertedValue
        );

        $convertedValue = str_replace(
            ['https://' . $host, 'http://' . $host],
            TemplateAliasHelper::CMS_WIDGET_URL_PLACEHOLDER,
            $convertedValue
        );
    }

    /**
     * Extract cms block loading it from template extrapolated string
     *
     * @param string $stringParams
     * @return array
     * @throws DataConversionException
     * @throws LocalizedException
     */
    protected function extractCmsBlock(string $stringParams) : array
    {
        /** @var Parameter $tokenizer */
        $tokenizer = $this->parameterFactory->create();
        $tokenizer->setString($stringParams);
        $cmsBlockParameters = $tokenizer->tokenize();
        if (isset($cmsBlockParameters['block_id'])) {
            $cmsBlock = $this->cmsBlockRepository->getById($cmsBlockParameters['block_id']);
            return [
                "block_id" => $cmsBlock->getId(),
                "identifier" => $cmsBlock->getIdentifier(),
                "content" => $this->convert($cmsBlock->getContent(), true)
            ];
        }
        return [];
    }

    /**
     * @param $value
     * @return bool
     */
    protected function isValidJsonValue($value) : bool
    {
        return parent::isValidJsonValue($this->normalizer->restoreReservedCharacters($value));
    }

    /**
     * @param $paramsString
     * @return string
     * @throws DataConversionException
     */
    private function convertWidgetParams($paramsString) : string
    {
        /** @var Parameter $tokenizer */
        $tokenizer = $this->parameterFactory->create();
        $tokenizer->setString($paramsString);
        $widgetParameters = $tokenizer->tokenize();

        $keysToUnserialize = [];
        foreach(array_keys($widgetParameters) as $key) {
            if (str_contains($key, 'repeatable_') || $key === 'conditions_encoded') {
                $keysToUnserialize[] = $key;
            }
        }

        // Scan every parameter for media assets, not just the ones the rewrite pass below
        // touches: a plain (non-repeatable) image parameter never reaches that pass at all,
        // and it only recognises absolute URLs, so a bare relative path - the format the
        // media browser actually stores - was skipped even inside a repeatable row.
        foreach ($widgetParameters as $key => $value) {
            if (in_array($key, $keysToUnserialize, true)) {
                if ($this->isValidJsonValue($value)) {
                    $decodedRows = $this->json->unserialize($this->normalizer->restoreReservedCharacters($value));
                    foreach ((array)$decodedRows as $row) {
                        foreach ((array)$row as $fieldValue) {
                            if (is_string($fieldValue)) {
                                $this->collectMediaAsset($fieldValue);
                            }
                        }
                    }
                }
            } elseif (is_string($value)) {
                $this->collectMediaAsset($value);
            }
        }

        if (!empty($keysToUnserialize)) {
            foreach ($keysToUnserialize as $key) {
                if ($this->isValidJsonValue($widgetParameters[$key])) {
                    $widgetConditionsEncoded = $this->json->unserialize(
                        $this->normalizer->restoreReservedCharacters($widgetParameters[$key])
                    );
                    foreach ($widgetConditionsEncoded as &$item) {
                        foreach ($item as $label => $value) {
                            if (filter_var($value, FILTER_VALIDATE_URL) && $url = parse_url($value)) {
                                $item[$label] = str_replace($url["scheme"] .
                                    "://" . $url["host"], TemplateAliasHelper::CMS_WIDGET_URL_PLACEHOLDER, $value);
                                $path = $url["path"] ?? '';
                                if (!$this->isCollectableMediaPath($path)) {
                                    $this->messageManager->addWarningMessage(
                                        (string)__('Skipped template asset with unsafe media path: %1', $value)
                                    );
                                } elseif (!in_array($path, $this->assets)) {
                                    $this->assets[] = $path;
                                }
                            }
                        }
                    }
                    $widgetParameters[$key] = $this->json->serialize($widgetConditionsEncoded);
                }
                $widgetParameters[$key] = $this->normalizer->replaceReservedCharacters(
                    parent::convert($widgetParameters[$key])
                );
            }
            $paramsString = '';
            foreach ($widgetParameters as $key => $parameter) {
                $paramsString .= ' ' . $key . '="' . $parameter . '"';
            }
        }

        return $paramsString;
    }
}
