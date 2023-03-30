<?php

namespace StripeIntegration\Payments\Observer;

class BeforeCheckout implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->helper = $helper;
        $this->config = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        if (!$this->helper->hasSubscriptions())
            return;

        $customerModel = $this->helper->getCustomerModel();
        if (!$customerModel->getStripeId())
            return;

        $stripeCustomer = $customerModel->retrieveByStripeID();
        if (empty($stripeCustomer->currency))
            return;

        $store = $this->helper->getCurrentStore();
        $newCurrency = strtoupper($stripeCustomer->currency);
        $currenctCurrency = $store->getCurrentCurrencyCode();

        if ($newCurrency != $currenctCurrency)
        {
            $availableCurrencyCodes = $store->getAvailableCurrencyCodes(true);

            if (!in_array($newCurrency, $availableCurrencyCodes))
                return;

            $store->setCurrentCurrencyCode($newCurrency);
            $url = $this->helper->getUrl('checkout');

            $observer->getControllerAction()
                        ->getResponse()
                        ->setRedirect($url);
        }
    }
}
