<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Exception\CouldNotSaveException;
use Magento\Framework\Exception\LocalizedException;

class SubscriptionUpdates
{
    protected $_refundCreditBalance = false;

    public function __construct(
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Api\Data\ShippingInformationInterfaceFactory $shippingInformationFactory,
        \Magento\Checkout\Api\ShippingInformationManagementInterface $shippingInformationManagement,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Checkout\Model\Session $checkoutSession,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \StripeIntegration\Payments\Model\Config $config
    )
    {
        $this->customerSession = $customerSession;
        $this->shippingInformationFactory = $shippingInformationFactory;
        $this->shippingInformationManagement = $shippingInformationManagement;
        $this->storeManager = $storeManager;
        $this->cartManagement = $cartManagement;
        $this->eventManager = $eventManager;
        $this->checkoutSession = $checkoutSession;
        $this->helper = $helper;
        $this->addressHelper = $addressHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->config = $config;
    }

    public function getCurrentStripeSubscriptionModel()
    {
        $updateDetails = $this->getSubscriptionUpdateDetails();

        if (!$updateDetails)
        {
            return null;
        }

        $model = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($updateDetails['_data']['subscription_id']);

        return $model;
    }

    public function getSubscriptionUpdateDetails()
    {
        $updateDetails = $this->checkoutSession->getSubscriptionUpdateDetails();

        if (isset($updateDetails['_data']['subscription_id']))
        {
            return $updateDetails;
        }

        return null;
    }

    // The logic generally follows the premise that if a new payment is collected, a new order must be created to provide
    // an invoice to the customer and record the transaction internally. In all other cases (including proration refunds)
    // there is no need to create a new order because a) no transaction took place, and b) the merchant should not fulfil
    // an order just yet, they should wait until a payment for the new goods is received.
    public function shouldPlaceNewOrder(\StripeIntegration\Payments\Model\Stripe\Subscription $currentStripeSubscription)
    {
        $quote = $this->helper->getQuote();
        $subscriptions = $this->subscriptionsHelper->getSubscriptionsFromQuote($quote);
        $combinedProfile = $this->subscriptionsHelper->getCombinedProfileFromSubscriptions($subscriptions);
        $newCanonicalAmount = $this->subscriptionsHelper->getCanonicalAmount($combinedProfile['stripe_amount'], $combinedProfile['interval'], $combinedProfile['interval_count']);

        $newProductIds = $combinedProfile["product_ids"];

        $hasProrations = $currentStripeSubscription->useProrations($newCanonicalAmount, $newProductIds);

        $this->_refundCreditBalance = false;

        if (!$hasProrations)
        {
            // There is no scenario where we collect a payment if there are no prorations
            return false;
        }
        else
        {
            $priceChange = $currentStripeSubscription->getPriceChange($newCanonicalAmount);

            if ($priceChange > 0)
            {
                // A new payment will be collected, so we want to create a new order
                return true;
            }
            else
            {
                // An online credit memo will be created against the original order.
                $this->validateCreditBalance($currentStripeSubscription->getStripeObject());
                $this->_refundCreditBalance = true;
                return false;
            }
        }
    }

    // Make sure that the customer does not already have a credit balance from an external integration.
    // The prorated transaction amount should be deterministic so that we record the transaction in Magento.
    protected function validateCreditBalance(\Stripe\Subscription $subscription)
    {
        $customer = $this->config->getStripeClient()->customers->retrieve($subscription->customer);

        if ($customer->balance != 0)
            throw new LocalizedException(__("A prorated subscription update is not possible because your customer account already has a credit balance. Please contact us for assistance."));
    }

    public function performUpdate($billingAddress, $shippingAddress = null, $shippingMethod = null, $couponCode = null)
    {
        if (!$this->customerSession->isLoggedIn())
        {
            throw new \Exception("Please log in and try again.");
        }

        $quote = $this->helper->getQuote();
        if (!$quote || !$quote->getId())
            throw new \Exception("The customer session does not have a quote.");

        $currentStripeSubscriptionModel = $this->getCurrentStripeSubscriptionModel();

        // Set the billing address
        $data = $this->addressHelper->filterAddressData($billingAddress);
        $quote->getBillingAddress()->addData($data);

        // Set the shipping address
        if (!$quote->getIsVirtual())
        {
            $data = $this->addressHelper->filterAddressData($shippingAddress);
            $quote->getShippingAddress()->addData($data);

            // Set the shipping method
            if (!empty($shippingMethod['carrier_code']) && !empty($shippingMethod['method_code']))
            {
                /** @var \Magento\Checkout\Api\Data\ShippingInformationInterface $shippingInformation */
                $shippingInformation = $this->shippingInformationFactory->create();
                $shippingInformation
                    ->setShippingAddress($quote->getShippingAddress())
                    ->setShippingCarrierCode($shippingMethod['carrier_code'])
                    ->setShippingMethodCode($shippingMethod['method_code']);

                $this->shippingInformationManagement->saveAddressInformation($quote->getId(), $shippingInformation);
            }
        }

        // Set the coupon code
        if ($couponCode)
        {
            $quote->setCouponCode($couponCode);
        }
        else
        {
            $quote->setCouponCode(null);
        }

        // Update totals
        $quote->setTotalsCollectedFlag(false);
        $quote->collectTotals();

        // For multi-stripe account configurations, load the correct Stripe API key from the correct store view
        $this->storeManager->setCurrentStore($quote->getStoreId());
        $this->config->initStripe();

        $quote->setCheckoutMethod(\Magento\Checkout\Model\Type\Onepage::METHOD_CUSTOMER);

        $quote->getPayment()->importData(['method' => 'stripe_payments', 'additional_data' => [
            'subscription_update' => true,
        ]]);

        // Save Quote
        $this->helper->saveQuote($quote);

        if ($this->shouldPlaceNewOrder($currentStripeSubscriptionModel))
        {
            // Place Order
            /** @var \Magento\Sales\Model\Order $order */
            $order = $this->cartManagement->submit($quote);
            if ($order)
            {
                $this->helper->setProcessingState($order, __("The subscription has been updated successfully."));
                $this->helper->saveOrder($order);

                $this->eventManager->dispatch(
                    'checkout_type_onepage_save_order_after',
                    ['order' => $order, 'quote' => $quote]
                );

                $this->checkoutSession
                    ->setLastQuoteId($quote->getId())
                    ->setLastSuccessQuoteId($quote->getId())
                    ->setLastOrderId($order->getId())
                    ->setLastRealOrderId($order->getIncrementId())
                    ->setLastOrderStatus($order->getStatus());

                /** @var \Magento\Framework\DataObject $payment */
                $payment = $order->getPayment();
                $invoiceAmountPaid = $payment->getAdditionalInformation("stripe_invoice_amount_paid");
                $invoiceCurrency = $payment->getAdditionalInformation("stripe_invoice_currency");
                $this->creditmemoHelper->refundUnderchargedOrder($order, $invoiceAmountPaid, $invoiceCurrency);
            }
            else
            {
                throw new \Exception("The order could not be placed.");
            }

            $this->eventManager->dispatch(
                'checkout_submit_all_after',
                [
                    'order' => $order,
                    'quote' => $quote
                ]
            );
        }
        else
        {
            $subscriptionUpdateDetails = $this->getSubscriptionUpdateDetails();
            if (!$subscriptionUpdateDetails)
                throw new \Exception("The subscription update details could not be read from the checkout session.");

            $latestInvoiceId = $currentStripeSubscriptionModel->getStripeObject()->latest_invoice;

            $currentStripeSubscriptionModel->performUpdate();

            $quote->setIsUsedForRecurringOrders(true);
            $quote->setIsActive(false);
            $this->helper->saveQuote($quote);

            $originalOrder = null;
            try
            {
                if (empty($subscriptionUpdateDetails['_data']['original_order_increment_id']))
                {
                    throw new \Exception("The subscription update data did not reference an original order increment ID.");
                }

                $originalOrder = $this->helper->loadOrderByIncrementId($subscriptionUpdateDetails['_data']['original_order_increment_id']);
                $paymentAmount = $currentStripeSubscriptionModel->getFormattedAmount();
                $billingDate = date("jS M Y", $subscriptionUpdateDetails['_data']['current_period_end']);
                $comment = __("Successfully updated subscription upon customer request. A payment of %1 will be collected on %2. A new order will be created from cart ID %3 upon payment collection.", $paymentAmount, $billingDate, $quote->getId());
                $this->helper->addOrderComment($comment, $originalOrder);
                $this->helper->saveOrder($originalOrder);
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not add subscription update order comments: " . $e->getMessage());
            }

            $this->refundCreditBalance($currentStripeSubscriptionModel->getStripeObject(), $latestInvoiceId, $originalOrder);
        }
    }

    public function refundCreditBalance(\Stripe\Subscription $subscription, $refundFromInvoiceId, $order = null)
    {
        if (!$this->_refundCreditBalance)
            return;

        // Once we have refunded the balance, a charge.refunded event will arrive and will create a credit memo against the original order
        try
        {
            $latestInvoice = $this->config->getStripeClient()->invoices->retrieve($refundFromInvoiceId, ['expand' => ['payment_intent', 'customer']]);

            if (empty($latestInvoice->customer->id))
                throw new \Exception("The last invoice for this subscription is not associated with a customer.");

            if ($latestInvoice->customer->balance >= 0)
                return;

            if (empty($latestInvoice->payment_intent->id))
                throw new \Exception("The last invoice for this subscription does not have a payment intent.");

            if (-$latestInvoice->customer->balance > $latestInvoice->payment_intent->amount)
                throw new \Exception("The customer credit balance is larger than the available amount to refund.");

            if ($order)
            {
                $refundAmount = $this->helper->formatStripePrice(-$latestInvoice->customer->balance, $latestInvoice->currency);
                $comment = __("The customer has an unused credit balance of %1 from their previous subscription. We will refund the amount to the customer.", $refundAmount);
                $this->helper->addOrderComment($comment, $order);
                $this->helper->saveOrder($order);
            }

            $this->config->getStripeClient()->refunds->create([
              'payment_intent' => $latestInvoice->payment_intent->id,
              'amount' => -$latestInvoice->customer->balance
            ]);

            $this->config->getStripeClient()->customers->update($latestInvoice->customer->id, [
              'balance' => 0
            ]);
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Unable to refund customer credit balance: " . $e->getMessage(), $e->getTraceAsString());
            return;
        }
    }
}
