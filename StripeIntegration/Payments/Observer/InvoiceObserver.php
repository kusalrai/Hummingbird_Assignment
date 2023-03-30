<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\Observer;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use StripeIntegration\Payments\Helper\Logger;

class InvoiceObserver extends AbstractDataAssignObserver
{
    public function __construct(
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper
    )
    {
        $this->orderRepository = $orderRepository;
        $this->paymentsHelper = $paymentsHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();

        switch($eventName)
        {
            case "sales_invoice_pay":
                break;
            case "sales_order_invoice_save_after":
                break;
            default:
                break;
        }
    }
}
