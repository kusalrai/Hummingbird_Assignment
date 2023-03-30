<?php

namespace StripeIntegration\Payments\Helper;

/**
 * MINIMAL DEPENDENCIES HELPER
 * No dependencies on other helper classes.
 * This class can be injected into installation scripts, cron jobs, predispatch observers etc.
 */
class Data
{
    public function __construct(
        \Magento\Framework\App\State $appState,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\Stdlib\DateTime $dateTime
    ) {
        $this->appState = $appState;
        $this->storeManager = $storeManager;
        $this->scopeConfig = $scopeConfig;
        $this->dateTime = $dateTime;
    }

    public function cleanToken($token)
    {
        if (empty($token))
            return null;

        return preg_replace('/-.*$/', '', $token);
    }

    public function isAdmin()
    {
        $areaCode = $this->appState->getAreaCode();

        return $areaCode == \Magento\Backend\App\Area\FrontNameResolver::AREA_CODE;
    }

    public function getConfigData($field)
    {
        $storeId = $this->storeManager->getStore()->getId();

        return $this->scopeConfig->getValue($field, \Magento\Store\Model\ScopeInterface::SCOPE_STORE, $storeId);
    }

    public function isMOTOError(\Stripe\ErrorObject $error)
    {
        if (empty($error->code))
            return false;

        if (empty($error->param))
            return false;

        if ($error->code != "parameter_unknown")
            return false;

        if ($error->param != "payment_method_options[card][moto]")
            return false;

        return true;
    }

    public function convertToSetupIntentConfirmParams($paymentIntentConfirmParams)
    {
        $confirmParams = $paymentIntentConfirmParams;

        if (!empty($confirmParams['payment_method_options']))
        {
            foreach ($confirmParams['payment_method_options'] as $key => $value)
            {
                if (isset($confirmParams['payment_method_options'][$key]['setup_future_usage']))
                    unset($confirmParams['payment_method_options'][$key]['setup_future_usage']);

                if (!in_array($key, \StripeIntegration\Payments\Helper\PaymentMethod::SETUP_INTENT_PAYMENT_METHOD_OPTIONS))
                    unset($confirmParams['payment_method_options'][$key]);

                if (empty($confirmParams['payment_method_options'][$key]))
                    unset($confirmParams['payment_method_options'][$key]);
            }

            if (empty($confirmParams['payment_method_options']))
                unset($confirmParams['payment_method_options']);
        }

        return $confirmParams;
    }

    public function getConfigurableProductBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        if ($orderItem->getProductType() != "configurable")
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        if (empty($productOptions['info_buyRequest']))
            return null;

        return $productOptions['info_buyRequest'];
    }

    public function getBuyRequest($orderItem)
    {
        if (!$orderItem || !$orderItem->getId())
            return null;

        $productOptions = $orderItem->getProductOptions();
        if (!$productOptions)
            return null;

        if (empty($productOptions['info_buyRequest']))
            return null;

        return new \Magento\Framework\DataObject($productOptions['info_buyRequest']);
    }

    public function dbTime()
    {
        return $this->dateTime->formatDate(true);
    }

    public function areArrayValuesTheSame(array $array1, array $array2)
    {
        $combined = array_merge($array1, $array2);
        $unique = array_unique($combined);

        if (count($unique) != count($array1))
            return false;

        if (count($unique) != count($array2))
            return false;

        return true;

    }
}
