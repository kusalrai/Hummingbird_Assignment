<?php

namespace StripeIntegration\Payments\Plugin\Checkout;

use Magento\Framework\Exception\CouldNotSaveException;

class GuestPaymentInformationManagement
{
    private $cartManagement;

    public function __construct(
        \Magento\Quote\Api\GuestCartManagementInterface $cartManagement,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper
    ) {

        $this->cartManagement = $cartManagement;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
    }

    public function afterSavePaymentInformation(
        \Magento\Checkout\Api\GuestPaymentInformationManagementInterface $subject,
        $result,
        $cartId,
        $email,
        \Magento\Quote\Api\Data\PaymentInterface $paymentMethod,
        \Magento\Quote\Api\Data\AddressInterface $billingAddress = null
    ) {
        $this->checkoutSessionHelper->updateCustomerEmail($email);

        return $result;
    }
}
