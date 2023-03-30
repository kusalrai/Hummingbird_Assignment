<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Subscription extends StripeObject
{
    protected $objectSpace = 'subscriptions';
    protected $canUpgradeDowngrade;
    protected $canChangeShipping;
    protected $useProrations;
    protected $orderItems = [];
    protected $subscriptionProductModels = [];
    protected $order;

    public function fromSubscriptionId($subscriptionId)
    {
        $this->getObject($subscriptionId);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The subscription \"%1\" could not be found in Stripe: %2", $subscriptionId, $this->lastError));

        $this->fromSubscription($this->object);

        return $this;
    }

    public function fromSubscription(\Stripe\Subscription $subscription)
    {
        $this->setObject($subscription);

        $productIDs = $this->getProductIDs();
        $order = $this->getOrder();

        if (empty($productIDs) || empty($order))
            return $this;

        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $subscriptionProductFactory = $objectManager->create(\StripeIntegration\Payments\Model\SubscriptionProductFactory::class);
        $orderItems = $order->getAllItems();
        foreach ($orderItems as $orderItem)
        {
            if (in_array($orderItem->getProductId(), $productIDs))
            {
                $this->orderItems[$orderItem->getId()] = $orderItem;
                $this->subscriptionProductModels[$orderItem->getId()] = $subscriptionProductFactory->create()->fromOrderItem($orderItem);
            }
        }

        return $this;
    }

    public function getOrder()
    {
        if (isset($this->order))
            return $this->order;

        $orderIncrementId = $this->getOrderID();
        if (empty($orderIncrementId))
            return null;

        $order = $this->helper->loadOrderByIncrementId($orderIncrementId);
        if (!$order || !$order->getId())
            return null;

        return $this->order = $order;
    }

    public function getOrderItems()
    {
        return $this->orderItems;
    }

    public function getSubscriptionProductModels()
    {
        return $this->subscriptionProductModels;
    }

    public function canUpgradeDowngrade()
    {
        if (isset($this->canUpgradeDowngrade))
            return $this->canUpgradeDowngrade;

        if (!$this->config->isSubscriptionsEnabled())
            return $this->canUpgradeDowngrade = false;

        if ($this->object->status != "active")
            return $this->canUpgradeDowngrade = false;

        if ($this->isCompositeSubscription())
            return $this->canUpgradeDowngrade = false;

        foreach ($this->subscriptionProductModels as $subscriptionProduct)
        {
            if ($subscriptionProduct->canUpgradeDowngrade())
            {
                return $this->canUpgradeDowngrade = true;
            }
        }

        return $this->canUpgradeDowngrade = false;
    }

    public function canChangeShipping()
    {
        if (isset($this->canChangeShipping))
            return $this->canChangeShipping;

        if (!$this->config->isSubscriptionsEnabled())
            return $this->canChangeShipping = false;

        if ($this->object->status != "active")
            return $this->canChangeShipping = false;

        foreach ($this->subscriptionProductModels as $subscriptionProduct)
        {
            if ($subscriptionProduct->canChangeShipping())
            {
                return $this->canChangeShipping = true;
            }
        }

        return $this->canChangeShipping = false;
    }

    public function getPriceChange(float $newCanonicalAmount)
    {
        $oldAmount = round($this->getCanonicalAmount(), 4);
        $newAmount = round($newCanonicalAmount, 4);

        return ($newAmount - $oldAmount);
    }

    public function isProductsSwitch($newProductIds)
    {
        $oldProductIds = $this->getProductIDs();
        $isProductsSwitch = !$this->dataHelper->areArrayValuesTheSame($oldProductIds, $newProductIds);

        if ($isProductsSwitch)
        {
            return true;
        }
        else
        {
            return false;
        }
    }

    public function isVirtualToVirtualProductSwitch($newProductIds)
    {
        $oldProductIds = $this->getProductIDs();
        $allProductIds = array_merge($oldProductIds, $newProductIds);

        try
        {
            foreach ($allProductIds as $productId)
            {
                $product = $this->helper->loadProductById($productId);

                if (!$product)
                    throw new \Exception("Could not load subscription product with ID " . $productId);

                if (!$product->getTypeId())
                    throw new \Exception("Product with ID " . $productId . " does not have a type.");

                if ($product->getTypeId() != "virtual")
                    return false;
            }

            return true;
        }
        catch (\Exception $e)
        {
            $this->helper->logError($e->getMessage(), $e->getTraceAsString());
            return false;
        }
    }

    public function useProrations(float $newCanonicalAmount, array $newProductIds)
    {
        if (isset($this->useProrations))
        {
            return $this->useProrations;
        }

        if (!$this->config->isSubscriptionsEnabled())
        {
            return $this->useProrations = false;
        }

        if (!$this->isProductsSwitch($newProductIds))
        {
            return $this->useProrations = false;
        }

        if (!$this->isVirtualToVirtualProductSwitch($newProductIds))
        {
            return $this->useProrations = false;
        }

        $priceChange = $this->getPriceChange($newCanonicalAmount);

        if ($priceChange == 0)
        {
            // In this case, even though the amount is the same, the products have changed, so we need to place a new order to track the change.
            // New orders are placed with subscription upgrades only, so we use the "Prorations for Upgrades" setting.
            // If the setting is disabled, we will not use prorations. Its up to the merchant to enable it.
            $isUpgrade = $this->isUpgrade = true;
            $isDowngrade = $this->isDowngrade = false;
        }
        else if ($priceChange < 0)
        {
            $isUpgrade = $this->isUpgrade = false;
            $isDowngrade = $this->isDowngrade = true;
        }
        else
        {
            $isUpgrade = $this->isUpgrade = true;
            $isDowngrade = $this->isDowngrade = false;
        }

        // If the quote is not virtual, do not use prorations
        if (!$this->helper->getQuote()->isVirtual())
            return $this->useProrations = false;

        $result = null;
        foreach ($this->subscriptionProductModels as $subscriptionProduct)
        {
            $useProrationsForUpgrades = $subscriptionProduct->useProrationsForUpgrades();
            $useProrationsForDowngrades = $subscriptionProduct->useProrationsForDowngrades();

            if (($isUpgrade && $useProrationsForUpgrades) || ($isDowngrade && $useProrationsForDowngrades))
            {
                $useProrations = true;
            }
            else
            {
                $useProrations = false;
            }

            if ($result !== null && $useProrations !== $result)
            {
                // Two products in the cart have different proration configurations. In this case disable prorations.
                return $this->useProrations = false;
            }

            $result = $useProrations;
        }

        return $this->useProrations = (bool)$result;
    }

    public function isVirtualSubscription()
    {
        $productIDs = $this->getProductIDs();

        if (empty($productIDs))
            return false;

        foreach ($productIDs as $productId)
        {
            $product = $this->helper->loadProductById($productId);
            if (!$product || !$product->getId())
                return false;

            if ($product->getTypeId() != "virtual")
                return false;
        }

        return true;
    }

    public function getProductIDs()
    {
        $productIDs = [];
        $subscription = $this->object;

        if (isset($subscription->metadata->{"Product ID"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"Product ID"});
        }
        else if (isset($subscription->metadata->{"SubscriptionProductIDs"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"SubscriptionProductIDs"});
        }

        return $productIDs;
    }

    public function getOrderID()
    {
        $subscription = $this->object;

        if (isset($subscription->metadata->{"Order #"}))
        {
            return $subscription->metadata->{"Order #"};
        }

        return null;
    }

    public function getCanonicalAmount()
    {
        $subscription = $this->object;

        if (empty($subscription->items->data[0]->price->unit_amount))
            throw new \Exception("This subscription has no price data.");

        // As of v3.3, subscriptions are combined in a single unit
        $stripeAmount = $subscription->items->data[0]->price->unit_amount;
        $interval = $subscription->items->data[0]->price->recurring->interval;
        $intervalCount = $subscription->items->data[0]->price->recurring->interval_count;

        return $this->subscriptionsHelper->getCanonicalAmount($stripeAmount, $interval, $intervalCount);
    }

    public function isCompositeSubscription()
    {
        $productIDs = $this->getProductIDs();

        return (count($productIDs) > 1);
    }

    public function getUpcomingInvoiceAfterUpdate($prorationTimestamp)
    {
        if (!$this->object)
            throw new \Exception("No subscription specified.");

        $subscription = $this->object;

        if (empty($subscription->items->data[0]->price->id))
            throw new \Exception("This subscription has no price data.");

        // The subscription update will happen based on the quote items
        $quote = $this->helper->getQuote();
        $subscriptions = $this->subscriptionsHelper->getSubscriptionsFromQuote($quote);
        $subscriptionItems = $this->subscriptionsHelper->getSubscriptionItemsFromQuote($quote, $subscriptions);

        $oldPriceId = $subscription->plan->id;
        $newPriceId = $subscriptionItems[0]['price'];

        $combinedProfile = $this->subscriptionsHelper->getCombinedProfileFromSubscriptions($subscriptions);
        $newCanonicalAmount = $this->subscriptionsHelper->getCanonicalAmount($combinedProfile['stripe_amount'], $combinedProfile['interval'], $combinedProfile['interval_count']);
        $newProductIds = explode(",", $subscriptionItems[0]["metadata"]["SubscriptionProductIDs"]);

        // See what the next invoice would look like with a price switch
        // and proration set:
        $items = [
          [
            'id' => $subscription->items->data[0]->id,
            'price' => $newPriceId, # Switch to new price
          ],
        ];

        $params = [
          'customer' => $subscription->customer,
          'subscription' => $subscription->id,
          'subscription_items' => $items
        ];

        if ($this->useProrations($newCanonicalAmount, $newProductIds))
        {
            $params['subscription_proration_date'] = $prorationTimestamp;
            $params['subscription_proration_behavior'] = "always_invoice";
        }
        else
        {
            $params['subscription_proration_behavior'] = "none";
        }

        $invoice = \Stripe\Invoice::upcoming($params);
        $invoice->oldPriceId = $oldPriceId;
        $invoice->newPriceId = $newPriceId;

        return $invoice;
    }

    public function performUpdate(\Magento\Payment\Model\InfoInterface $payment = null)
    {
        if (!$this->object)
            throw new \Exception("No subscription to update from.");

        $subscription = $this->object;
        $originalOrderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);

        if (empty($subscription->items->data))
        {
            throw new \Exception("There are no subscription items to update");
        }

        if (count($subscription->items->data) > 1)
        {
            throw new \Exception("Updating a subscription with multiple subscription items is not implemented.");
        }

        $order = ($payment ? $payment->getOrder() : null);

        $quote = $this->helper->getQuote();
        $subscriptions = $this->subscriptionsHelper->getSubscriptionsFromQuote($quote);
        $subscriptionItems = $this->subscriptionsHelper->getSubscriptionItemsFromQuote($quote, $subscriptions, $order);

        if (count($subscriptionItems) > 1)
        {
            throw new \Exception("Updating a subscription with multiple subscription items is not implemented.");
        }

        $subscriptionItems[0]['id'] = $subscription->items->data[0]->id;

        $params = [
            "cancel_at_period_end" => false,
            "items" => $subscriptionItems,
            "metadata" => $subscriptionItems[0]['metadata'] // There is only one item for the entire order,
        ];

        if ($order)
        {
            $params["description"] = $this->helper->getOrderDescription($order);
        }
        else
        {
            $nextPaymentDate = date("jS M Y", $subscription->current_period_end);
            $params["description"] = __("Updated subscription of original order #%1. Pending payment and new order on %2.", $originalOrderIncrementId, $nextPaymentDate);
            $params["metadata"]["Original Order #"] = $originalOrderIncrementId;
            $params["metadata"]["Order #"] = null;
        }

        $combinedProfile = $this->subscriptionsHelper->getCombinedProfileFromSubscriptions($subscriptions);
        $newCanonicalAmount = $this->subscriptionsHelper->getCanonicalAmount($combinedProfile['stripe_amount'], $combinedProfile['interval'], $combinedProfile['interval_count']);
        $newProductIds = explode(",", $subscriptionItems[0]["metadata"]["SubscriptionProductIDs"]);

        if ($this->useProrations($newCanonicalAmount, $newProductIds))
        {
            $checkoutSession = $this->helper->getCheckoutSession();
            $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();

            if (!empty($subscriptionUpdateDetails['_data']['proration_timestamp']))
                $prorationTimestamp = $subscriptionUpdateDetails['_data']['proration_timestamp'];
            else
                $prorationTimestamp = time();

            $params["proration_behavior"] = "always_invoice";
            $params["proration_date"] = $prorationTimestamp;
            $payment && $payment->setIsTransactionPending(0);
        }
        else
        {
            $params["proration_behavior"] = "none";

            if ($this->changingPlanIntervals($subscription, $combinedProfile['interval'], $combinedProfile['interval_count']))
            {
                $params["trial_end"] = $subscription->current_period_end;
            }

            $payment && $payment->setIsTransactionPending(1);
        }

        $newPriceId = $subscriptionItems[0]['price'];

        try
        {
            $updatedSubscription = $this->config->getStripeClient()->subscriptions->update($subscription->id, $params);
            $this->setObject($updatedSubscription);
        }
        catch (\Stripe\Exception\InvalidRequestException $e)
        {
            $error = $e->getError();
            throw new \Magento\Framework\Exception\LocalizedException(__($error->message));
        }

        try
        {
            $subscriptionModel = $this->subscriptionsHelper->loadSubscriptionModelBySubscriptionId($updatedSubscription->id);
            $subscriptionModel->initFrom($updatedSubscription, $order);
            $subscriptionModel->setLastUpdated($this->dataHelper->dbTime());
            if (!$payment)
            {
                $subscriptionModel->setReorderFromQuoteId($quote->getId());
            }
            $subscriptionModel->save();
        }
        catch (\Stripe\Exception\InvalidRequestException $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
        }

        $this->helper->addSuccess(__("Your subscription has been updated successfully."));

        $originalOrder = $this->helper->loadOrderByIncrementId($originalOrderIncrementId);

        if ($payment)
        {
            try
            {
                if (empty($updatedSubscription->latest_invoice))
                    throw new \Exception("No invoice exists for the updated subscription.");

                $invoice = $this->config->getStripeClient()->invoices->retrieve($updatedSubscription->latest_invoice, ['expand' => ['payment_intent']]);
                if (empty($invoice->payment_intent))
                    throw new \Exception("The invoice does not have a payment intent.");

                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
                $paymentIntentModel = $objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
                $paymentIntentModel->setTransactionDetails($payment, $invoice->payment_intent);
                $payment->setAdditionalInformation("stripe_invoice_amount_paid", $invoice->amount_paid);
                $payment->setAdditionalInformation("stripe_invoice_currency", $invoice->currency);
            }
            catch (\Exception $e)
            {
                $this->paymentsHelper->logError("Could not set subscription transaction details: " . $e->getMessage());
            }

            $payment->setAdditionalInformation("is_subscription_update", true);
            $payment->setAdditionalInformation("subscription_id", $subscription->id);
            $payment->setAdditionalInformation("original_order_increment_id", $originalOrderIncrementId);
            $payment->setAdditionalInformation("customer_stripe_id", $subscription->customer);

            $subscriptionUpdateDetails = $this->helper->getCheckoutSession()->getSubscriptionUpdateDetails();
            if (!empty($subscriptionUpdateDetails['_data']['comments']))
            {
                $payment->getOrder()->addStatusToHistory($status = null, $comment = $subscriptionUpdateDetails['_data']['comments'], $isCustomerNotified = false);
            }

            if ($originalOrder && $originalOrder->getId())
            {
                $originalOrder->getPayment()->setAdditionalInformation("new_order_increment_id", $payment->getOrder()->getIncrementId());
            }
        }

        if ($originalOrder && $originalOrder->getId())
        {
            $previousSubscriptionAmount = $this->subscriptionsHelper->formatInterval(
                $subscription->plan->amount,
                $subscription->plan->currency,
                $subscription->plan->interval_count,
                $subscription->plan->interval
            );
            $originalOrder->getPayment()->setAdditionalInformation("previous_subscription_amount", (string)$previousSubscriptionAmount);
            $this->helper->saveOrder($originalOrder);

            // @todo - refund the original order here

        }

        $this->helper->getCheckoutSession()->unsSubscriptionUpdateDetails();

        return $updatedSubscription;
    }

    public function getFormattedAmount()
    {
        $subscription = $this->object;

        return $this->helper->formatStripePrice($subscription->plan->amount, $subscription->plan->currency);
    }

    public function getFormattedBilling()
    {
        $subscription = $this->object;

        return $this->subscriptionsHelper->getInvoiceAmount($subscription) . " " .
                $this->subscriptionsHelper->formatDelivery($subscription) . " " .
                $this->subscriptionsHelper->formatLastBilled($subscription);
    }

    private function changingPlanIntervals($subscription, $interval, $intervalCount)
    {
        if ($subscription->plan->interval != $interval)
            return true;

        if ($subscription->plan->interval_count != $intervalCount)
            return true;

        return false;
    }
}
