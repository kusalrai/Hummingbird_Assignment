<?php

namespace StripeIntegration\Payments\Observer;

class AfterRemoveCartItem implements \Magento\Framework\Event\ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->config = $config;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        try
        {
            $this->subscriptionsHelper->cancelSubscriptionUpdate();
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
        }
    }
}
