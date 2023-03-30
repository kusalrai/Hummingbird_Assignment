<?php

namespace Hummingbird\Mod9\Helper;

class Data extends \Magento\Framework\App\Helper\AbstractHelper
{
    const IS_ENABLED = 'mod9_tab/general/enable';
    const TEXT = 'mod9_tab/general/text';

    protected $scopeConfig;

    public function __construct(
        \Magento\Framework\App\Helper\Context $context,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    ) {
        parent::__construct($context);
        $this->scopeConfig = $scopeConfig;
    }

    public function isEnabled()
    {
        $isEnabled = $this->scopeConfig->getValue(self::IS_ENABLED,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE);

        return $isEnabled;
    }

    public function getDisplayText()
    {
        $displayText = $this->scopeConfig->getValue(self::TEXT,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE);
        return $displayText;
    }
}