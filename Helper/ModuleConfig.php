<?php
declare(strict_types=1);

namespace MageOS\PageBuilderTemplateImportExport\Helper;

use Magento\Framework\App\Helper\AbstractHelper;
use Magento\Framework\Serialize\SerializerInterface;
use Magento\Framework\App\Helper\Context;

class ModuleConfig extends AbstractHelper
{
    const SECTION = 'cms/';
    const GENERAL_GROUP = self::SECTION . 'pagebuilder_template_importexport/';
    const ENABLE = self::GENERAL_GROUP . 'enable';
    const SYNC_TEMPLATES_BY_CRON = self::GENERAL_GROUP . 'sync_templates_by_cron';
    const DROPBOX_CREDENTIALS = self::GENERAL_GROUP . 'dropbox_credentials';

    /**
     * @param SerializerInterface $serializer
     * @param Context $context
     */
    public function __construct(
        protected SerializerInterface $serializer,
        Context $context
    ) {
        parent::__construct($context);
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::ENABLE);
    }

    /**
     * @return mixed
     */
    public function getDropboxCredentials(): mixed
    {
        $dropboxCredentials = $this->scopeConfig->getValue(self::DROPBOX_CREDENTIALS);

        if (!is_array($dropboxCredentials)) {
            if (!is_string($dropboxCredentials) || $dropboxCredentials === '') {
                return [];
            }
            $dropboxCredentials = $this->serializer->unserialize($dropboxCredentials);
        }

        return $dropboxCredentials;
    }

    /**
     * @param string $appKey
     * @return mixed
     */
    public function getDropboxAccountCredentialsByAppKey(string $appKey): mixed
    {
        $credentials = $this->getDropboxCredentials();
        if (!is_array($credentials)) {
            return false;
        }
        foreach ($credentials as $accountCredential) {
            if ($accountCredential["app_key"] === $appKey) {
                return $accountCredential;
            }
        }
        return false;
    }

    /**
     * @return bool
     */
    public function isQueueManagementEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::SYNC_TEMPLATES_BY_CRON,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE
        );
    }
}
