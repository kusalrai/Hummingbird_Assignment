<?php
namespace StripeIntegration\Payments\Plugin\Multishipping;

class Helper
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper
    ) {
        $this->config = $config;
        $this->helper = $helper;
    }

    public function aroundIsMultishippingCheckoutAvailable(\Magento\Multishipping\Helper\Data $subject, \Closure $proceed)
    {
        if ($this->config->isSubscriptionsEnabled() && $this->helper->hasSubscriptions())
            return false;

        return $proceed();
    }
}
