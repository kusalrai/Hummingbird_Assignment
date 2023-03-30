<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class CancelInvoice implements ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Api\OrderManagementInterface $orderManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \StripeIntegration\Payments\Helper\Serializer $serializer
    )
    {
        $this->helper = $helper;
        $this->config = $config;
        $this->orderManagement = $orderManagement;
        $this->_stripeCustomer = $helper->getCustomerModel();
        $this->_eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
        $this->serializer = $serializer;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $payment = $observer->getPayment();
        $method = $payment->getMethod();

        if ($method != 'stripe_payments_invoice')
        {
            return;
        }

        if (!$this->helper->isAdmin())
        {
            return;
        }

        $invoice = $observer->getInvoice();
        $order = $invoice->getOrder();
        $invoiceId = $payment->getAdditionalInformation('invoice_id');

        try
        {
            $this->config->getStripeClient()->invoices->voidInvoice($invoiceId, []);
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }
    }
}
