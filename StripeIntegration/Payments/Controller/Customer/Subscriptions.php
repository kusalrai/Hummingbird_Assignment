<?php

namespace StripeIntegration\Payments\Controller\Customer;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Exception\LocalizedException;

class Subscriptions extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \Magento\Customer\Model\Session $session,
        \Magento\Framework\DataObject\Factory $dataObjectFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Sales\Model\Order $order
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);

        $session = $session;
        $this->dataObjectFactory = $dataObjectFactory;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->compare = $compare;
        $this->dataHelper = $dataHelper;
        $this->order = $order;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->config = $config;

        if (!$session->isLoggedIn())
            $this->_redirect('customer/account/login');
    }

    public function execute()
    {
        $params = $this->getRequest()->getParams();

        if (isset($params['viewOrder']))
            return $this->viewOrder($params['viewOrder']);
        else if (isset($params['update']))
            return $this->updateSubscription($params['update']);
        else if (isset($params['updateSuccess']))
            return $this->onUpdateSuccess();
        else if (isset($params['updateCancel']))
            return $this->onUpdateCancel();
        else if (isset($params['cancel']))
            return $this->cancelSubscription($params['cancel']);
        else if (isset($params['changeCard']))
            return $this->changeCard($params['changeCard'], $params['subscription_card']);
        else if (isset($params['changeShipping']))
            return $this->changeShipping($params['changeShipping']);
        else if (!empty($params))
            $this->_redirect('stripe/customer/subscriptions');

        return $this->resultPageFactory->create();
    }

    protected function onUpdateCancel()
    {
        $this->subscriptionsHelper->cancelSubscriptionUpdate();
        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function onUpdateSuccess()
    {
        $this->helper->addSuccess(__("The subscription has been updated successfully."));
        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function viewOrder($incrementOrderId)
    {
        $this->order->loadByIncrementId($incrementOrderId);

        if ($this->order->getId())
            $this->_redirect('sales/order/view/order_id/' . $this->order->getId());
        else
        {
            $this->helper->addError("Order #$incrementOrderId could not be found!");
            $this->_redirect('stripe/customer/subscriptions');
        }
    }

    protected function cancelSubscription($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new \Exception("Could not load customer account for subscription with ID $subscriptionId!");

            $subscription = $this->stripeCustomer->getSubscription($subscriptionId);
            $this->subscriptionFactory->create()->cancel($subscriptionId);
            $this->helper->addSuccess(__("The Subscription has been canceled successfully!"));
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be canceled. Please contact us for more help."));
            $this->helper->logError("Could not cancel subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        $this->_redirect('stripe/customer/subscriptions');
    }

    protected function changeCard($subscriptionId, $cardId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new \Exception("Could not load customer account for subscription with ID $subscriptionId!");

            $subscription = \Stripe\Subscription::update($subscriptionId, ['default_payment_method' => $cardId]);

            $this->helper->addSuccess(__("The subscription has been updated."));
        }
        catch (\Exception $e)
        {
            $this->helper->addError("Sorry, the subscription could not be updated. Please contact us for more help.");
            $this->helper->logError("Could not edit subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        $this->_redirect('stripe/customer/subscriptions');
    }

    protected function updateSubscription($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new LocalizedException(__("Could not load customer account."));

            $subscriptionId = $this->getRequest()->getParam("update", null);
            if (!$subscriptionId)
                throw new LocalizedException(__("Invalid subscription ID."));

            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, []);
            $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
            if (!$orderIncrementId)
                throw new LocalizedException(__("This subscription is not associated with an order."));

            $order = $this->helper->loadOrderByIncrementId($orderIncrementId);

            if (!$order)
                throw new LocalizedException(__("Could not load order for this subscription."));

            $configurableProduct = $this->subscriptionsHelper->getConfigurableSubscriptionProductFrom($subscription);
            if (!$configurableProduct)
                throw new LocalizedException(__("Could not load subscription product."));

            $buyRequest = null;
            foreach ($order->getAllVisibleItems() as $orderItem)
            {
                if ($orderItem->getProductId() == $configurableProduct->getId())
                {
                    $buyRequest = $this->dataHelper->getConfigurableProductBuyRequest($orderItem);
                }
            }

            if (!$buyRequest)
                throw new LocalizedException(__("Could not load the original order items."));

            $newRequest = [
                "product" => $buyRequest['product'],
                'super_attribute' => $this->getRequest()->getParam('super_attribute', $buyRequest['super_attribute']),
                'qty' => $this->getRequest()->getParam('qty', 1)
            ];

            if (!empty($buyRequest['options']))
                $newRequest['options'] = $buyRequest['options'];

            $request = $this->dataObjectFactory->create($newRequest);
            $quote = $this->helper->getQuote();
            $quote->removeAllItems();
            $quote->removeAllAddresses();
            $extensionAttributes = $quote->getExtensionAttributes();
            $extensionAttributes->setShippingAssignments([]);
            $result = $quote->addProduct($configurableProduct, $request);
            if (is_string($result))
                throw new LocalizedException(__($result));

            $this->setSubscriptionUpdateDetails($subscription, [ $configurableProduct->getId() ]);

            $quote->getShippingAddress()->setCollectShippingRates(false);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->helper->saveQuote($quote);
            try
            {
                if (!$order->getIsVirtual() && !$quote->getIsVirtual() && $order->getShippingMethod())
                {
                    $shippingMethod = $order->getShippingMethod();
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAddress->addData($order->getShippingAddress()->getData());
                    $shippingAddress->setCollectShippingRates(true)
                            ->collectShippingRates()
                            ->setShippingMethod($order->getShippingMethod())
                            ->save();
                }
            }
            catch (\Exception $e)
            {
                // The shipping address or method may not be available, ignore in this case
            }

            return $this->_redirect('checkout');
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be updated. Please contact us for more help."));
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->_redirect('stripe/customer/subscriptions');
    }

    protected function changeShipping($subscriptionId)
    {
        try
        {
            if (!$this->stripeCustomer->getStripeId())
                throw new LocalizedException(__("Could not load customer account."));

            if (!$subscriptionId)
                throw new LocalizedException(__("Invalid subscription ID."));

            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, []);
            $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
            if (!$orderIncrementId)
                throw new LocalizedException(__("This subscription is not associated with an order."));

            $order = $this->helper->loadOrderByIncrementId($orderIncrementId);

            if (!$order)
                throw new LocalizedException(__("Could not load order for this subscription."));

            $quote = $this->helper->getQuote();
            $quote->removeAllItems();
            $quote->removeAllAddresses();
            $extensionAttributes = $quote->getExtensionAttributes();
            $extensionAttributes->setShippingAssignments([]);

            $productIds = $this->subscriptionsHelper->getSubscriptionProductIDs($subscription);
            $items = $order->getItemsCollection();
            foreach ($items as $item)
            {
                $subscriptionProductModel = $this->subscriptionProductFactory->create()->fromOrderItem($item);

                if ($subscriptionProductModel->isSubscriptionProduct() &&
                    $subscriptionProductModel->getProduct() &&
                    $subscriptionProductModel->getProduct()->isSaleable() &&
                    in_array($subscriptionProductModel->getProduct()->getId(), $productIds)
                    )
                {
                    $product = $subscriptionProductModel->getProduct();

                    if ($item->getParentItem() && $item->getParentItem()->getProductType() == "configurable")
                    {
                        $item = $item->getParentItem();
                        $product = $this->helper->loadProductById($item->getProductId());

                        if (!$product || !$product->isSaleable())
                            continue;
                    }

                    $request = $this->dataHelper->getBuyRequest($item);
                    $result = $quote->addProduct($product, $request);
                    if (is_string($result))
                        throw new LocalizedException(__($result));
                }
            }

            if (!$quote->hasItems())
                throw new LocalizedException(__("Sorry, this subscription product is currently unavailable."));

            $this->setSubscriptionUpdateDetails($subscription, $productIds);

            $quote->getShippingAddress()->setCollectShippingRates(false);
            $quote->setTotalsCollectedFlag(false)->collectTotals();
            $this->helper->saveQuote($quote);
            try
            {
                if (!$order->getIsVirtual() && !$quote->getIsVirtual() && $order->getShippingMethod())
                {
                    $shippingMethod = $order->getShippingMethod();
                    $shippingAddress = $quote->getShippingAddress();
                    $shippingAddress->addData($order->getShippingAddress()->getData());
                    $shippingAddress->setCollectShippingRates(true)
                            ->collectShippingRates()
                            ->setShippingMethod($order->getShippingMethod())
                            ->save();
                }
            }
            catch (\Exception $e)
            {
                // The shipping address or method may not be available, ignore in this case
            }

            return $this->_redirect('checkout');
        }
        catch (LocalizedException $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage());
        }
        catch (\Exception $e)
        {
            $this->helper->addError(__("Sorry, the subscription could not be updated. Please contact us for more help."));
            $this->helper->logError("Could not update subscription with ID $subscriptionId: " . $e->getMessage(), $e->getTraceAsString());
        }

        return $this->_redirect('stripe/customer/subscriptions');
    }

    public function setSubscriptionUpdateDetails($subscription, $productIds)
    {
        // Last billed
        $startDate = $subscription->created;
        $date = $subscription->current_period_start;

        if ($startDate > $date)
        {
            $lastBilled = null;
        }
        else
        {
            $day = date("j", $date);
            $sup = date("S", $date);
            $month = date("F", $date);
            $year = date("y", $date);

            $lastBilled =  __("%1<sup>%2</sup>&nbsp;%3&nbsp;%4", $day, $sup, $month, $year);
        }

        // Remaining days
        $remainingTime = $subscription->current_period_end - time();
        $remainingDays = 0;
        do
        {
            $remainingTime -= (60 * 60 * 24);
            if ($remainingTime > 0)
                $remainingDays++;
        }
        while ($remainingTime > 0);

        if ($remainingDays == 0)
            $remainingDays = __("Ends today!");
        else
            $remainingDays = __("%1 days", $remainingDays);

        // Next billing date
        $day = date("j", $subscription->current_period_end);
        $sup = date("S", $subscription->current_period_end);
        $month = date("F", $subscription->current_period_end);
        $year = date("y", $subscription->current_period_end);
        $nextBillingDate = __("%1<sup>%2</sup>&nbsp;%3&nbsp;%4", $day, $sup, $month, $year);

        $checkoutSession = $this->helper->getCheckoutSession();
        $checkoutSession->setSubscriptionUpdateDetails([
            "_data" => [
                "subscription_id" => $subscription->id,
                "original_order_increment_id" => $this->subscriptionsHelper->getSubscriptionOrderID($subscription),
                "product_ids" => $productIds,
                "current_period_end" => $subscription->current_period_end,
                "current_period_start" => $subscription->current_period_start,
                "proration_timestamp"=> time()
            ],
            "current_price_label" => $this->subscriptionsHelper->getInvoiceAmount($subscription) . " " . $this->subscriptionsHelper->formatDelivery($subscription),
            "last_billed_label" => $lastBilled,
            "remaining_time_label" => $remainingDays,
            "next_billing_date" => $nextBillingDate
        ]);
    }
}
