<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\DataConverter;

use Magento\Framework\Data\Wysiwyg\Normalizer;
use Magento\Framework\DB\DataConverter\DataConversionException;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filter\Template\Tokenizer\Parameter;
use Magento\Framework\Filter\Template\Tokenizer\ParameterFactory;
use Magento\Framework\DB\DataConverter\SerializedToJson;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\Serialize\Serializer\Serialize;
use Magento\Cms\Api\BlockRepositoryInterface;
use MageOS\PageBuilderTemplateImportExport\Helper\Aliases as TemplateAliasHelper;

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
     * @param Normalizer $normalizer
     * @param ParameterFactory $parameterFactory
     * @param Json $json
     * @param BlockRepositoryInterface $cmsBlockRepository
     * @param Serialize $serialize
     */
    public function __construct(
        protected Normalizer $normalizer,
        protected ParameterFactory $parameterFactory,
        protected Json $json,
        protected BlockRepositoryInterface $cmsBlockRepository,
        protected Serialize $serialize
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
                $url = explode("=", $match[2])[1];
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
        if ($child) {
            return $convertedValue;
        }
        return ["value" => $convertedValue, "assets" => $this->assets, "children" => $this->cmsBlocks];
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
                                if (!in_array($url["path"], $this->assets)) {
                                    $this->assets[] = $url["path"];
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
