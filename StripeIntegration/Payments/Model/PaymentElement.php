<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\Exception\LocalizedException;
use StripeIntegration\Payments\Exception\SCANeededException;

class PaymentElement extends \Magento\Framework\Model\AbstractModel
{
    protected $paymentIntent = null;
    protected $setupIntent = null;
    protected $subscription = null;
    protected $clientSecrets = [];

    public function __construct(
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\PaymentIntent $paymentIntentHelper,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentModelFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\Customer\Model\AddressFactory $addressFactory,
        \Magento\Quote\Model\QuoteFactory $quoteFactory,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Framework\Session\Generic $session,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
        )
    {
        $this->dataHelper = $dataHelper;
        $this->helper = $helper;
        $this->compare = $compare;
        $this->paymentIntentModelFactory = $paymentIntentModelFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->cache = $context->getCacheManager();
        $this->config = $config;
        $this->customer = $helper->getCustomerModel();
        $this->quoteFactory = $quoteFactory;
        $this->quoteRepository = $quoteRepository;
        $this->addressFactory = $addressFactory;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->paymentIntentHelper = $paymentIntentHelper;
        $this->session = $session;
        $this->checkoutHelper = $checkoutHelper;

        parent::__construct($context, $registry, $resource, $resourceCollection, $data);
    }

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\PaymentElement');
    }

    public function getClientSecret($quoteId, $paymentMethodId = null, $order = null)
    {
        if ($order && $order->getStoreId())
        {
            $this->config->reInitStripeFromStoreId($order->getStoreId()); // Necessary in order to load the quote
        }

        $quote = $this->helper->loadQuoteById($quoteId);

        if (!$quote || !$quote->getId())
        {
            $this->helper->logError("Cannot create client secret: A quote is not available.");
            return null;
        }

        $this->load($quote->getId(), 'quote_id');

        // This will hit if the method is called twice in the same request
        if (!empty($this->clientSecrets[$quote->getId()]))
            return $this->clientSecrets[$quote->getId()];

        if (!$this->getQuoteId()) // Not found
            $this->setQuoteId($quote->getId())->save();

        // The flow is to:
        // 1. Create subscriptions if any
        // 2. Create a payment intent if none exists on the subscription
        // 3. Create a setup intent if we still don't have a payment intent

        $paymentIntent = $setupIntent = null;
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $params = $paymentIntentModel->getParamsFrom($quote, $order, $paymentMethodId);

        try
        {
            $subscription = $this->subscriptionsHelper->updateSubscriptionFromQuote($quote, $this->getSubscriptionId(), $params);

            if ($subscription) // The cart may or may not include subscriptions
            {
                $quoteDescription = $this->helper->getQuoteDescription($quote);

                if (!empty($subscription->latest_invoice->payment_intent->id))
                {
                    $paymentIntent = $subscription->latest_invoice->payment_intent;
                    $isSubscription = ($paymentIntent->description == "Subscription creation");
                    $isUpdatedCart = ((strpos($paymentIntent->description, "Cart ") === 0) && ($paymentIntent->description != $quoteDescription));
                    if ($isSubscription || $isUpdatedCart)
                    {
                        $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, [
                            "description" => $this->helper->getQuoteDescription($quote)
                        ]);
                    }
                }

                $this->updateFromSubscription($subscription);
            }

            $this->subscription = $subscription;
        }
        catch (\Stripe\Exception\InvalidRequestException $e)
        {
            // Likely: You cannot combine currencies on a single customer. This customer has had a subscription, coupon, or invoice item with currency usd
            throw $e;
        }
        catch (\Exception $e)
        {
            // Example case where this hits: Subscriptions require a customer object. A customer could not be created
            // because we don't have a name or email on the quote. Turns out the merchant is using a OneStepCheckout module.
            // So we don't initialize the PaymentElement at all. We wait for the customer to enter a shipping or billing address.
            // Magento would normally call the PaymentMethod::isAvailable() method right after that which will trigger the
            // customer object creation and return the PE initialization params.
            $this->helper->logError("Cannot create client secret: " . $e->getMessage(), $e->getTraceAsString());
            return null;
        }

        // We only have subscriptions in the cart
        if ($params['amount'] == 0)
        {
            if (!empty($subscription->latest_invoice->payment_intent->client_secret))
            {
                $paymentIntent = $subscription->latest_invoice->payment_intent;
            }
            else if (!empty($subscription->pending_setup_intent->client_secret))
            {
                $setupIntent = $subscription->pending_setup_intent;
            }
            else
            {
                // A 100% discount coupon may have been applied
                return null;
            }
        }
        // We have a mixed cart
        else if ($params['amount'] > 0 && !empty($subscription))
        {
            if (!empty($subscription->latest_invoice->payment_intent->client_secret))
                $paymentIntent = $subscription->latest_invoice->payment_intent;
            else
                throw new \Exception("Mixed cart case not implemented");
        }
        else if ($params['amount'] > 0)
        {
            // Load previously created PI if it exists
            $paymentIntent = $paymentIntentModel->loadFromCache($params, $quote, $order, null, true);
            if (!$paymentIntent)
            {
                $paymentIntent = $paymentIntentModel->create($params, $quote, $order);
            }
        }

        if ($paymentIntent)
        {
            $this->setupIntent = null;
            $this->paymentIntent = $paymentIntent;
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($paymentIntent->id);
            $this->save();
            return $this->clientSecrets[$quote->getId()] = $paymentIntent->client_secret;
        }
        else if ($setupIntent)
        {
            $this->setupIntent = $setupIntent;
            $this->paymentIntent = null;
            $this->setSetupIntentId($setupIntent->id);
            $this->setPaymentIntentId(null);
            $this->save();
            return $this->clientSecrets[$quote->getId()] = $setupIntent->client_secret;

        }

        return null;
    }

    public function updateFromOrder($order, $paymentMethodId = null)
    {
        if (empty($order))
            throw new \Exception("No order specified.");

        $quote = $this->helper->loadQuoteById($order->getQuoteId());

        $this->load($quote->getId(), 'quote_id');

        if ($this->getOrderIncrementId() && $this->getOrderIncrementId() != $order->getIncrementId()
            && !$order->getPayment()->getAdditionalInformation('is_migrated_subscription'))
        {
            // Check if this is a duplicate order placement. The old order should have normally been canceled if the cart changed.
            $oldOrder = $this->helper->loadOrderByIncrementId($this->getOrderIncrementId());
            if ($oldOrder && $oldOrder->getState() != "canceled" && !$this->helper->isMultiShipping())
            {
                // The case where the old order was not canceled is when the payment failed and the cart contents changed
                $this->setOrderIncrementId($order->getIncrementId())->save();
                $comment = __("The cart contents or customer details have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $order->getIncrementId());
                $oldOrder->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
                $this->helper->removeTransactions($oldOrder);
                $this->helper->cancelOrCloseOrder($oldOrder);
            }
        }

        if (empty($this->getQuoteId()))
            throw new \Exception("Cannot update an order without a previously created Payment Element.");

        // Update any existing subscriptions
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $params = $paymentIntentModel->getParamsFrom($quote, $order, $paymentMethodId);
        $subscription = $this->subscriptionsHelper->updateSubscriptionFromOrder($order, $this->getSubscriptionId(), $params);
        if (!empty($subscription->id))
        {
            $this->updateFromSubscription($subscription);
            $order->getPayment()->setAdditionalInformation("subscription_id", $subscription->id);
            $this->subscription = $subscription;
        }

        $paymentIntent = $setupIntent = null;

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $paymentIntent = $subscription->latest_invoice->payment_intent;
        }
        else if (!empty($subscription->pending_setup_intent->id))
        {
            $setupIntent = $subscription->pending_setup_intent;
        }
        else
        {
            // Update any existing payment intents
            $paymentIntent = $paymentIntentModel->loadFromCache($params, $quote, $order, $this->getPaymentIntentId(), true);
            if (empty($paymentIntent) && $params['amount'] > 0)
                throw new LocalizedException(__("The cart details have changed. Please refresh the page and try again (1)."));
        }

        // Upon order placement, a customer is always created in Stripe
        if ($this->customer->getStripeId())
        {
            $this->customer->updateFromOrder($order);
            $params['customer'] = $this->customer->getStripeId();
        }

        if ($paymentIntent)
        {
            $this->setupIntent = null;
            $this->paymentIntent = $paymentIntent;
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($paymentIntent->id);

            $this->updatePaymentIntentFrom($paymentIntent, $params);
        }
        else if ($setupIntent)
        {
            $this->setupIntent = $setupIntent;
            $this->paymentIntent = null;
            $this->setSetupIntentId($setupIntent->id);
            $this->setPaymentIntentId(null);

            $this->updateSetupIntentFrom($setupIntent, $params);
        }

        $this->setOrderIncrementId($order->getIncrementId());
        $this->save();
    }

    // There are some cases where an error occurred after placing an order, and somehow the quote was
    // recreated, thus losing the reference to the old Pending order. In those cases, we want to search
    // and find those pending orders and manually cancel them before creating a new one using the same
    // payment intent ID. Having 2 orders with the same payment intent ID is very problematic.
    public function cancelInvalidOrders($currentOrder)
    {
        $transactionId = $this->getPaymentIntentId();

        if (!$transactionId || $this->helper->isMultiShipping())
        {
            return;
        }

        $orders = $this->helper->getOrdersByTransactionId($transactionId);
        $comment = __("The cart contents or customer details have changed. The order is canceled because a new one will be placed (#%1) with the new details.", $currentOrder->getIncrementId());

        foreach ($orders as $order)
        {
            try
            {
                $quote = $this->helper->loadQuoteById($order->getQuoteId());
                if ($this->helper->isMultiShipping($quote))
                {
                    continue;
                }
                $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
                $this->helper->removeTransactions($order);
                $this->helper->cancelOrCloseOrder($order);

                if ($currentOrder->getQuoteId() != $order->getQuoteId())
                {
                    $this->paymentIntentModelFactory->create()->destroy($order->getQuoteId());
                }
            }
            catch (\Exception $e)
            {
                $this->helper->logError("Could not cancel invalid order: " . $e->getMessage());
            }
        }
    }

    public function updatePaymentIntentFrom($paymentIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($paymentIntent, $params);

        if ($this->compare->isDifferent($paymentIntent, $updateParams))
            return $this->config->getStripeClient()->paymentIntents->update($paymentIntent->id, $updateParams);

        return $paymentIntent;
    }

    public function updateSetupIntentFrom($setupIntent, $params)
    {
        $updateParams = $this->getFilteredParamsForUpdate($setupIntent, $params);

        if ($this->compare->isDifferent($setupIntent, $updateParams))
            return $this->config->getStripeClient()->setupIntents->update($setupIntent->id, $updateParams);

        return $setupIntent;
    }

    protected function getFilteredParamsForUpdate($object, $params)
    {
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        $updateParams = $paymentIntentModel->getFilteredParamsForUpdate($params);

        // We can only set the customer, we cannot change it
        if (!empty($params["customer"]) && empty($object->customer))
            $updateParams['customer'] = $params["customer"];

        if ($this->getSetupIntent() || $this->getSubscription())
        {
            unset($updateParams['amount']); // If we have a subscription, the amount will be incorrect here: Order total - Subscriptions total
        }

        return ($updateParams ? $updateParams : []);
    }

    public function getSupportedPaymentMethodTypes()
    {
        if ($this->paymentIntent && !empty($this->paymentIntent->payment_method_types))
        {
            return $this->paymentIntent->payment_method_types;
        }
        else if ($this->setupIntent && !empty($this->setupIntent->payment_method_types))
        {
            return $this->setupIntent->payment_method_types;
        }
        else
        {
            return [];
        }
    }

    public function getSavedPaymentMethods($quoteId = null)
    {
        $this->mustBeInitialized();
        $customer = $this->helper->getCustomerModel();

        if (!$customer->getStripeId())
            return [];

        $quote = $this->helper->getQuote($quoteId);
        if (!$quote)
            return [];

        if (!$quoteId)
            $quoteId = $quote->getId();

        $supportedPaymentMethodTypes = $this->getSupportedPaymentMethodTypes();
        $supportedSavedPaymentMethodTypes = array_intersect($supportedPaymentMethodTypes, \StripeIntegration\Payments\Helper\PaymentMethod::CAN_BE_SAVED_ON_SESSION);

        if ($this->helper->hasSubscriptions($quote))
        {
            $supportedSavedPaymentMethodTypes = array_intersect($supportedSavedPaymentMethodTypes, \StripeIntegration\Payments\Helper\PaymentMethod::SUPPORTS_SUBSCRIPTIONS);
        }

        $savedMethods = $customer->getSavedPaymentMethods($supportedSavedPaymentMethodTypes, true);

        return $savedMethods;
    }

    public function isOrderPlaced()
    {
        $this->mustBeInitialized();

        return (bool)($this->getOrderIncrementId());
    }

    public function getSubscription()
    {
        return $this->subscription;
    }

    public function getPaymentIntent()
    {
        return $this->paymentIntent;
    }

    public function getSetupIntent()
    {
        return $this->setupIntent;
    }

    public function confirm($order)
    {
        $paymentIntentModel = $this->paymentIntentModelFactory->create();
        if ($confirmationObject = $this->getPaymentIntent())
        {
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->paymentIntent = $this->config->getStripeClient()->paymentIntents->update($confirmationObject->id, [
                    'description' => $this->helper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the PI on the client side and retries order placement
            if ($this->paymentIntentHelper->isSuccessful($confirmationObject) || $this->paymentIntentHelper->requiresOfflineAction($confirmationObject))
            {
                return $confirmationObject;
            }

            $confirmParams = $paymentIntentModel->getConfirmParams($order, $confirmationObject);

            try
            {
                $result = $this->config->getStripeClient()->paymentIntents->confirm($confirmationObject->id, $confirmParams);
                $this->paymentIntent = $result;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->paymentIntents->confirm($confirmationObject->id, $confirmParams);
                $this->paymentIntent = $result;
            }
        }
        else if ($confirmationObject = $this->getSetupIntent())
        {
            if (empty($confirmationObject->metadata->{'Order #'}))
            {
                $confirmationObject = $this->setupIntent = $this->config->getStripeClient()->setupIntents->update($confirmationObject->id, [
                    'description' => $this->helper->getOrderDescription($order),
                    'metadata' => $this->config->getMetadata($order)
                ]);
            }

            // Wallet button 3DS confirms the SI on the client side and retries order placement
            if ($confirmationObject->status == "succeeded")
            {
                return $confirmationObject;
            }

            $confirmParams = $paymentIntentModel->getConfirmParams($order, $confirmationObject);
            $confirmParams = $this->dataHelper->convertToSetupIntentConfirmParams($confirmParams);

            try
            {
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
            catch (\Stripe\Exception\InvalidRequestException $e)
            {
                if (!$this->dataHelper->isMOTOError($e->getError()))
                    throw $e;

                $this->cache->save($value = "1", $key = "no_moto_gate", ["stripe_payments"], $lifetime = 6 * 60 * 60);
                unset($confirmParams['payment_method_options']['card']['moto']);
                $result = $this->config->getStripeClient()->setupIntents->confirm($confirmationObject->id, $confirmParams);
                $this->setupIntent = $result;
            }
        }
        else
            throw new \Exception("Could not confirm payment.");

        if (!empty($result->status) && $result->status == "requires_action")
            throw new SCANeededException("Authentication Required");

        return $result;
    }

    protected function mustBeInitialized()
    {
        if (!$this->paymentIntent && !$this->setupIntent)
            throw new \Exception("The payment element has not been initialized.");
    }

    protected function updateFromSubscription($subscription)
    {
        if (empty($subscription->id))
            return;

        $this->setSubscriptionId($subscription->id);

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $this->setSetupIntentId(null);
            $this->setPaymentIntentId($subscription->latest_invoice->payment_intent->id);
            $this->setupIntent = null;
            $this->paymentIntent = $subscription->latest_invoice->payment_intent;
        }
        else if (!empty($subscription->pending_setup_intent->id))
        {
            $this->setSetupIntentId($subscription->pending_setup_intent->id);
            $this->setPaymentIntentId(null);
            $this->setupIntent = $subscription->pending_setup_intent;
            $this->paymentIntent = null;
        }

        $this->save();
    }

    protected function convertToSetupIntentParams($quote, $params)
    {
        $newParams = [];

        foreach ($params as $key => $value)
        {
            switch ($key)
            {
                case 'description':
                case 'customer':
                case 'metadata':
                    $newParams[$key] = $value;
                    break;
                default:
                    break;
            }
        }

        $usage = $this->config->getSetupFutureUsage($quote);
        if ($usage)
            $newParams['usage'] = $usage;

        return $newParams;
    }
}
