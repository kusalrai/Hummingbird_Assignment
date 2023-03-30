<?php

namespace StripeIntegration\Payments\Block\Multishipping;

use StripeIntegration\Payments\Helper\Logger;

// Payment method form in the multi-shipping page
class Billing extends \Magento\Payment\Block\Form\Cc
{
    protected $_template = 'multishipping/billing/card_element.phtml';

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Model\Config $paymentConfig,
        \Magento\Framework\Data\Form\FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $paymentConfig, $data);
        $this->formKey = $formKey;
    }

    public function getFormKey()
    {
         return $this->formKey->getFormKey();
    }
}
