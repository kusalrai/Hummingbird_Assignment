<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use StripeIntegration\Payments\Helper\Logger;

class OrderObserver extends AbstractDataAssignObserver
{
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \Magento\Framework\Session\SessionManagerInterface $sessionManager,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Checkout\Model\Session $checkoutSession
    )
    {
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->sessionManager = $sessionManager;
        $this->quoteRepository = $quoteRepository;
        $this->checkoutSession = $checkoutSession;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $order = $observer->getEvent()->getOrder();
        $eventName = $observer->getEvent()->getName();
        $method = $order->getPayment()->getMethod();

        if ($method == 'stripe_payments')
        {
            switch ($eventName)
            {
                case "sales_order_place_after":
                    $this->updateOrderState($observer);
                    break;
                case "sales_model_service_quote_submit_success":
                    break;
            }

        }

        // When a guest customer order is placed in the admin area, clear the session saved variables so that new ones are created in the next session
        if ($this->helper->isAdmin())
            $this->sessionManager->setStripeCustomerId(null);
    }

    public function updateOrderState($observer)
    {
        $order = $observer->getEvent()->getOrder();
        $payment = $order->getPayment();

        if ($payment->getAdditionalInformation('stripe_outcome_type') == "manual_review")
            $this->helper->holdOrder($order)->save();
    }
}
