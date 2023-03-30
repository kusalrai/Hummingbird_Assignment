<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

class Subscription extends \Magento\Framework\Model\AbstractModel
{
    /**
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
     * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
     * @param array $data
     */
    public function __construct(
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        $this->_config = $config;
        $this->quoteRepository = $quoteRepository;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Subscription');
    }

    public function getSubscriptionCurrency($subscription, $order)
    {
        if (!empty($subscription->plan->currency))
        {
            // Subscription was created with Stripe Elements and InvoiceItems
            $currency = $subscription->plan->currency;
        }
        else if (!empty($subscription->items->data))
        {
            // Subscription was created with Stripe Checkout and SubscriptionItems
            foreach ($subscription->items->data as $sub)
            {
                if (!empty($sub->plan->currency))
                {
                    $currency = $sub->plan->currency;
                }
            }
        }

        if (empty($currency))
            $currency = $order->getOrderCurrencyCode();

        return $currency;
    }

    public function initFrom($subscription, $order = null)
    {
        if (isset($subscription->plan->currency))
            $currency = $subscription->plan->currency;
        else if (isset($subscription->items->data[0]->plan->currency))
            $currency = $subscription->items->data[0]->plan->currency;
        else if ($order)
            $currency = strtolower($order->getOrderCurrencyCode());
        else
            $currency = "usd";

        $data = [
            "created_at" => $subscription->created,
            "livemode" => $subscription->livemode,
            "subscription_id" => $subscription->id,
            "stripe_customer_id" => $subscription->customer,
            "payment_method_id" => $subscription->default_payment_method,
            "quantity" => $subscription->quantity,
            "currency" => $currency,
        ];

        if ($order)
        {
            $data["store_id"] = $order->getStore()->getId();
            $data["order_increment_id"] = $order->getIncrementId();
            $data["magento_customer_id"] = $order->getCustomerId();
            $data["grand_total"] = $order->getGrandTotal();
        }
        else
        {
            $data["order_increment_id"] = null;
            $data["grand_total"] = 0;
        }

        $this->addData($data);

        return $this;
    }

    public function cancel($subscriptionId)
    {
        $this->_config->getStripeClient()->subscriptions->cancel($subscriptionId, []);

        $this->load($subscriptionId, "subscription_id");

        if ($this->getReorderFromQuoteId())
        {
            try
            {
                $quote = $this->quoteRepository->get($this->getReorderFromQuoteId());
                $quote->setIsUsedForRecurringOrders(false);
                $this->quoteRepository->save($quote);
            }
            catch (\Exception $e)
            {

            }
        }

        if ($this->getId())
        {
            $this->delete();
        }
    }
}
