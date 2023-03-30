<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Service;

use Magento\Framework\Exception\LocalizedException;

class OrderService
{
    protected $helper;
    protected $subscriptionsHelper;

    public function __construct(
        \StripeIntegration\Payments\Helper\Quote $quoteHelper,
        \StripeIntegration\Payments\Helper\GenericFactory $helperFactory,
        \StripeIntegration\Payments\Helper\SubscriptionsFactory $subscriptionsFactory
    ) {
        $this->quoteHelper = $quoteHelper;
        $this->helperFactory = $helperFactory;
        $this->subscriptionsFactory = $subscriptionsFactory;
    }

    public function aroundPlace($subject, \Closure $proceed, $order)
    {
        try
        {
            if (!empty($order) && !empty($order->getQuoteId()))
            {
                $this->quoteHelper->quoteId = $order->getQuoteId();
            }

            if ($this->hasMultipleSubscriptionProducts($order->getQuoteId()))
            {
                throw new LocalizedException(__("Only one subscription is allowed per order."));
            }

            $savedOrder = $proceed($order);

            $this->updateOrderStatus($savedOrder);

            return $savedOrder;
        }
        catch (\Exception $e)
        {
            $helper = $this->helperFactory->create();
            $msg = $e->getMessage();

            if ($helper->isAuthenticationRequiredMessage($msg))
                throw $e;
            else
                $helper->dieWithError($e->getMessage(), $e);
        }
    }

    public function updateOrderStatus($order)
    {
        $helper = $this->getHelper();
        switch ($order->getPayment()->getMethod())
        {
            case "stripe_payments_invoice":
                $comment = __("A payment is pending for this order.");
                $helper->setOrderState($order, \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT, $comment);
                $helper->saveOrder($order);
                break;
            default:
                break;
        }
    }

    public function hasMultipleSubscriptionProducts($quoteId)
    {
        $helper = $this->getHelper();
        $subscriptions = $this->getSubscriptionsHelper();
        $quote = $helper->loadQuoteById($quoteId);
        $products = [];
        foreach ($quote->getAllItems() as $quoteItem)
        {
            $product = $helper->loadProductById($quoteItem->getProductId());
            if ($product && $product->getId())
            {
                $products[] = $product;
            }
        }

        if ($subscriptions->hasMultipleSubscriptionProducts($products))
        {
            return true;
        }

        return false;
    }

    protected function getHelper()
    {
        if (!isset($this->helper))
        {
            $this->helper = $this->helperFactory->create();
        }

        return $this->helper;
    }

    protected function getSubscriptionsHelper()
    {
        if (!isset($this->subscriptionsHelper))
        {
            $this->subscriptionsHelper = $this->subscriptionsFactory->create();
        }

        return $this->subscriptionsHelper;
    }
}
