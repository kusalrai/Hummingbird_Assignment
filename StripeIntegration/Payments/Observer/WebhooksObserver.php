<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class WebhooksObserver implements ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Webhooks $webhooksHelper,
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\Order $orderHelper,
        \StripeIntegration\Payments\Helper\Creditmemo $creditmemoHelper,
        \StripeIntegration\Payments\Model\InvoiceFactory $invoiceFactory,
        \StripeIntegration\Payments\Model\PaymentIntentFactory $paymentIntentFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Helper\RecurringOrder $recurringOrderHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \Magento\Sales\Model\Order\Email\Sender\OrderCommentSender $orderCommentSender,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Sales\Model\Order\Payment\Transaction\Builder $transactionBuilder,
        \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
        \Magento\Sales\Api\InvoiceRepositoryInterface $invoiceRepository
    )
    {
        $this->webhooksHelper = $webhooksHelper;
        $this->paymentsHelper = $paymentsHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->addressHelper = $addressHelper;
        $this->orderHelper = $orderHelper;
        $this->creditmemoHelper = $creditmemoHelper;
        $this->invoiceFactory = $invoiceFactory;
        $this->paymentIntentFactory = $paymentIntentFactory;
        $this->config = $config;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->recurringOrderHelper = $recurringOrderHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->orderCommentSender = $orderCommentSender;
        $this->eventManager = $eventManager;
        $this->invoiceService = $invoiceService;
        $this->cache = $cache;
        $this->transactionBuilder = $transactionBuilder;
        $this->orderRepository = $orderRepository;
        $this->invoiceRepository = $invoiceRepository;
    }

    protected function orderAgeLessThan($minutes, $order)
    {
        $created = strtotime($order->getCreatedAt());
        $now = time();
        return (($now - $created) < ($minutes * 60));
    }

    public function wasCapturedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_captured_" . $object['id']))
        {
            return true;
        }

        if (!empty($object['payment_intent']) && is_string($object['payment_intent']) && $this->cache->load("admin_captured_" . $object['payment_intent']))
        {
            return true;
        }

        return false;
    }

    public function wasRefundedFromAdmin($object)
    {
        if (!empty($object['id']) && $this->cache->load("admin_refunded_" . $object['id']))
            return true;

        return false;
    }

    /**
     * @return void
     */
    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        $eventName = $observer->getEvent()->getName();
        $arrEvent = $observer->getData('arrEvent');
        $stdEvent = $observer->getData('stdEvent');
        $object = $observer->getData('object');
        $paymentMethod = $observer->getData('paymentMethod');
        $isAsynchronousPaymentMethod = false;

        switch ($eventName)
        {
            case 'stripe_payments_webhook_checkout_session_expired':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->addOrderComment($order, __("Stripe Checkout session has expired without a payment."));

                if ($this->paymentsHelper->isPendingCheckoutOrder($order))
                    $this->paymentsHelper->cancelOrCloseOrder($order);

                break;

            // Creates an invoice for an order when the payment is captured from the Stripe dashboard
            case 'stripe_payments_webhook_charge_captured':

                if ($this->wasCapturedFromAdmin($object))
                    return;

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                $payment = $order->getPayment();

                if (empty($object['payment_intent']))
                    return;

                $paymentIntentId = $object['payment_intent'];

                $chargeAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
                $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
                $transaction = $this->paymentsHelper->addTransaction($order, $paymentIntentId, $transactionType, $paymentIntentId);
                $transaction->setAdditionalInformation("amount", $chargeAmount);
                $transaction->setAdditionalInformation("currency", $object['currency']);
                $transaction->save();

                $humanReadableAmount = $this->paymentsHelper->addCurrencySymbol($chargeAmount, $object['currency']);
                $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", __("Captured"), $humanReadableAmount, $paymentIntentId);
                $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                $this->orderRepository->save($order);

                $params = [
                    "amount" => $object['amount_captured'],
                    "currency" => $object['currency']
                ];

                $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE;

                $this->paymentsHelper->invoiceOrder($order, $paymentIntentId, $captureCase, $params);

                break;

            case 'stripe_payments_webhook_review_closed':

                if (empty($object['payment_intent']))
                    return;

                $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

                foreach ($orders as $order)
                {
                    $this->eventManager->dispatch(
                        'stripe_payments_review_closed_before',
                        ['order' => $order, 'object' => $object]
                    );

                    if ($object['reason'] == "approved")
                    {
                        if (!$order->canHold())
                            $order->unhold();

                        $comment = __("The payment has been approved via Stripe.");
                        $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                        $this->paymentsHelper->saveOrder($order);
                    }
                    else
                    {
                        $comment = __("The payment was canceled through Stripe with reason: %1.", ucfirst(str_replace("_", " ", $object['reason'])));
                        $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                        $this->paymentsHelper->saveOrder($order);
                    }

                    $this->eventManager->dispatch(
                        'stripe_payments_review_closed_after',
                        ['order' => $order, 'object' => $object]
                    );
                }

                break;

            case 'stripe_payments_webhook_customer_subscription_updated':

                try
                {
                    $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                }
                catch (WebhookException $e)
                {
                    if ($e->statusCode == 202 && isset($object['metadata']['Original Order #']))
                    {
                        // This is a subscription update which did not generate a new order.
                        // Orders are not generated when there is no payment collected
                        // So there is nothing to do here, skip the case
                        break;
                    }
                    else
                    {
                        throw $e;
                    }
                }

                if (empty($order->getPayment()))
                    throw new WebhookException("Order #%1 does not have any associated payment details.", $order->getIncrementId());

                $paymentMethod = $order->getPayment()->getMethod();
                $invoiceId = $stdEvent->data->object->latest_invoice;
                $invoiceParams = [
                    'expand' => [
                        'subscription',
                        'payment_intent'
                    ]
                ];
                $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);

                $this->webhooksHelper->setPaymentDescriptionAfterSubscriptionUpdate($order, $invoice);

                break;

            case 'stripe_payments_webhook_customer_subscription_created':

                $subscription = $stdEvent->data->object;

                try
                {
                    $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                    $this->subscriptionsHelper->updateSubscriptionEntry($subscription, $order);
                }
                catch (\Exception $e)
                {
                    if ($object['status'] == "incomplete" || $object['status'] == "trialing")
                    {
                        // A PaymentElement has created an incomplete subscription which has no order yet
                        $this->subscriptionsHelper->updateSubscriptionEntry($subscription, null);
                    }
                    else
                    {
                        throw $e;
                    }
                }

                break;

            case 'stripe_payments_webhook_invoice_voided':
            case 'stripe_payments_webhook_invoice_marked_uncollectible':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                switch ($order->getPayment()->getMethod())
                {
                    case "stripe_payments_invoice":
                        $this->webhooksHelper->refundOfflineOrCancel($order);
                        $comment = __("The invoice was voided from the Stripe Dashboard.");
                        $order->addStatusToHistory(false, $comment, $isCustomerNotified = false);
                        $this->paymentsHelper->saveOrder($order);
                        break;
                }

                break;

            case 'stripe_payments_webhook_charge_refunded':

                if ($this->wasRefundedFromAdmin($object))
                    return;

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $result = $this->creditmemoHelper->refundFromStripeDashboard($order, $object);
                break;

            case 'stripe_payments_webhook_setup_intent_canceled':
            case 'stripe_payments_webhook_payment_intent_canceled':

                if ($object["status"] != "canceled")
                    break;

                $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

                foreach ($orders as $order)
                {
                    if ($object["cancellation_reason"] == "abandoned")
                    {
                        $msg = __("Customer abandoned the cart. The payment session has expired.");
                        $this->addOrderComment($order, $msg);
                        $this->paymentsHelper->cancelOrCloseOrder($order);
                    }
                }
                break;

            case 'stripe_payments_webhook_payment_intent_succeeded':

                break;

            case 'stripe_payments_webhook_setup_intent_setup_failed':
            case 'stripe_payments_webhook_payment_intent_payment_failed':

                $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);

                foreach ($orders as $order)
                {
                    if (!empty($object['last_payment_error']['message']))
                        $lastError = $object['last_payment_error'];
                    elseif (!empty($object['last_setup_error']['message']))
                        $lastError = $object['last_setup_error'];
                    else
                        $lastError = null;

                    if (!empty($lastError['message'])) // This is set with Stripe Checkout / redirect flow
                    {
                        switch ($lastError['code'])
                        {
                            case 'payment_intent_authentication_failure':
                                $msg = __("Payment authentication failed.");
                                break;
                            case 'payment_intent_payment_attempt_failed':
                                if (strpos($lastError['message'], "expired") !== false)
                                {
                                    $msg = __("Customer abandoned the cart. The payment session has expired.");
                                    $this->paymentsHelper->cancelOrCloseOrder($order);
                                }
                                else
                                    $msg = __("Payment failed: %1", $lastError['message']);
                                break;
                            default:
                                $msg = __("Payment failed: %1", $lastError['message']);
                                break;
                        }
                    }
                    else if (!empty($object['failure_message']))
                        $msg = __("Payment failed: %1", $object['failure_message']);
                    else if (!empty($object["outcome"]["seller_message"]))
                        $msg = __("Payment failed: %1", $object["outcome"]["seller_message"]);
                    else
                        $msg = __("Payment failed.");

                    $this->addOrderComment($order, $msg);
                }

                break;

            case 'stripe_payments_webhook_setup_intent_succeeded':

                // This is a trial subscription order for which no charge.succeeded event will be received
                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $paymentElement = $this->paymentElementFactory->create()->load($object['id'], 'setup_intent_id');
                if (!$paymentElement->getId())
                    break;

                if (!$paymentElement->getSubscriptionId())
                    break;

                $subscription = $this->config->getStripeClient()->subscriptions->retrieve($paymentElement->getSubscriptionId());

                $updateData = [];

                if (empty($subscription->metadata->{"Order #"}))
                {
                    // With PaymentElement subscriptions, the subscription object is created before the order is placed,
                    // and thus it does not have the order number at creation time.
                    $updateData["metadata"] = ["Order #" => $order->getIncrementId()];
                }

                if (!empty($object['payment_method']))
                    $updateData['default_payment_method'] = $object['payment_method'];

                if (!empty($updateData))
                    $this->config->getStripeClient()->subscriptions->update($subscription->id, $updateData);

                if ($subscription->status != "trialing")
                    break;

                // Trial subscriptions should still be fulfilled. A new order will be created when the trial ends.
                $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
                $status = $order->getConfig()->getStateDefaultStatus($state);
                $comment = __("Your trial period for order #%1 has started.", $order->getIncrementId());
                $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = true);

                if ($this->subscriptionsHelper->isZeroAmountOrder($order))
                {
                    if (!$order->getEmailSent())
                    {
                        $this->paymentsHelper->sendNewOrderEmailFor($order, true);
                    }

                    // There will be no charge.succeeded event for trial subscription orders, so create the invoice here.
                    // Then refund the amount that was not collected for the trial subscription. This is because when the
                    // subscription activates, a new order will be created with a separate invoice.
                    $this->paymentsHelper->invoiceOrder($order, null, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    $baseInitialFee = 0; // @todo
                    $baseRefundTotal = $order->getBaseGrandTotal() - $baseInitialFee;
                    $creditmemo = $this->creditmemoHelper->refundOfflineOrderBaseAmount($order, $baseRefundTotal);
                    $this->creditmemoHelper->save($creditmemo);
                }

                $this->paymentsHelper->saveOrder($order);

                break;

            case 'stripe_payments_webhook_source_chargeable':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->webhooksHelper->charge($order, $object);
                break;

            case 'stripe_payments_webhook_source_canceled':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $canceled = $this->paymentsHelper->cancelOrCloseOrder($order);
                if ($canceled)
                    $this->addOrderCommentWithEmail($order, "Sorry, your order has been canceled because a payment request was sent to your bank, but we did not receive a response back. Please contact us or place your order again.");
                break;

            case 'stripe_payments_webhook_source_failed':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);

                $this->paymentsHelper->cancelOrCloseOrder($order);
                $this->addOrderCommentWithEmail($order, "Your order has been canceled because the payment authorization failed.");
                break;

            case 'stripe_payments_webhook_charge_succeeded':

                if (!empty($object['metadata']['Multishipping']))
                {
                    $orders = $this->webhooksHelper->loadOrderFromEvent($arrEvent, true);
                    $this->deduplicatePaymentMethod($object, $orders[0]); // We only want to do this once

                    $paymentIntentModel = $this->paymentIntentFactory->create();

                    foreach ($orders as $order)
                        $this->orderHelper->onMultishippingChargeSucceeded($order, $object);

                    return;
                }

                if ($this->wasCapturedFromAdmin($object))
                    break;

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                $hasSubscriptions = $this->paymentsHelper->hasSubscriptionsIn($order->getAllItems());

                $stripeInvoice = null;
                if (!empty($object['invoice']))
                {
                    $stripeInvoice = $this->config->getStripeClient()->invoices->retrieve($object['invoice'], []);
                    if ($stripeInvoice->billing_reason == "subscription_cycle" // A subscription has renewed
                        || $stripeInvoice->billing_reason == "subscription_update" // A trial subscription was manually ended
                        || $stripeInvoice->billing_reason == "subscription_threshold" // A billing threshold was reached
                    )
                    {
                        // We may receive a charge.succeeded event from a recurring subscription payment. In that case we want to create
                        // a new order for the new payment, rather than registering the charge against the original order.
                        break;
                    }
                }

                if (!$order->getEmailSent())
                {
                    $isPaymentElement = $order->getPayment()->getAdditionalInformation("client_side_confirmation");
                    $isStripeCheckout = $order->getPayment()->getAdditionalInformation("checkout_session_id");

                    if ($isPaymentElement || $isStripeCheckout)
                    {
                        $this->paymentsHelper->sendNewOrderEmailFor($order);
                    }
                }

                $this->deduplicatePaymentMethod($object, $order);

                if (empty($object['payment_intent']))
                    throw new WebhookException("This charge was not created by a payment intent.");

                $transactionId = $object['payment_intent'];

                $payment = $order->getPayment();
                $payment->setTransactionId($transactionId)
                    ->setLastTransId($transactionId)
                    ->setIsTransactionPending(false)
                    ->setIsTransactionClosed(0)
                    ->setIsFraudDetected(false)
                    ->save();

                $amountCaptured = ($object["captured"] ? $object['amount_captured'] : 0);

                $this->orderHelper->onTransaction($order, $object, $transactionId);

                if ($amountCaptured > 0)
                {
                    // We intentionally do not pass $params in order to avoid multi-currency rounding errors.
                    // For example, if $order->getGrandTotal() == $16.2125, Stripe will charge $16.2100. If we
                    // invoice for $16.2100, then there will be an order total due for 0.0075 which will cause problems.
                    // $params = [
                    //     "amount" => $amountCaptured,
                    //     "currency" => $object['currency']
                    // ];
                    $this->paymentsHelper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, $params = null, true);
                }
                else if ($amountCaptured == 0) // Authorize Only mode
                {
                    if ($hasSubscriptions)
                    {
                        // If it has trial subscriptions, we want a Paid invoice which will partially refund
                        $this->paymentsHelper->invoiceOrder($order, $transactionId, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE, null, true);
                    }
                    else
                    {
                        if ($this->config->isAutomaticInvoicingEnabled())
                        {
                            $this->paymentsHelper->invoicePendingOrder($order, $transactionId);
                        }
                    }
                }

                if ($this->config->isStripeRadarEnabled() && !empty($object['outcome']['type']) && $object['outcome']['type'] == "manual_review")
                    $this->paymentsHelper->holdOrder($order);

                $this->paymentsHelper->saveOrder($order);

                if (!empty($stripeInvoice) && $stripeInvoice->status == "paid")
                {
                    $this->creditmemoHelper->refundUnderchargedOrder($order, $stripeInvoice->amount_paid, $stripeInvoice->currency);
                }

                // Update the payment intents table, because the payment method was created after the order was placed
                $paymentIntentModel = $this->paymentIntentFactory->create()->load($object['payment_intent'], 'pi_id');
                $quoteId = $paymentIntentModel->getQuoteId();
                if ($quoteId == $order->getQuoteId())
                {
                    $paymentIntentModel->setPmId($object['payment_method']);
                    $paymentIntentModel->setOrderId($order->getId());
                    if (is_numeric($order->getCustomerId()) && $order->getCustomerId() > 0)
                        $paymentIntentModel->setCustomerId($order->getCustomerId());
                    $paymentIntentModel->save();
                }

                break;

            // Recurring subscription payments
            case 'stripe_payments_webhook_invoice_payment_succeeded':

                try
                {
                    $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                }
                catch (\StripeIntegration\Payments\Exception\SubscriptionUpdatedException $e)
                {
                    try
                    {
                        if ($object['billing_reason'] == "subscription_cycle")
                        {
                            return $this->recurringOrderHelper->createFromQuoteId($e->getQuoteId(), $object['id']);
                        }
                        else /* if ($object['billing_reason'] == "subscription_update") */
                        {
                            // At the very first subscription update (prorated or not), do not create a recurring order.
                            return;
                        }
                    }
                    catch (\Exception $e)
                    {
                        $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                        throw $e;
                    }
                }

                if (empty($order->getPayment()))
                    throw new WebhookException("Order #%1 does not have any associated payment details.", $order->getIncrementId());

                $paymentMethod = $order->getPayment()->getMethod();
                $invoiceId = $stdEvent->data->object->id;
                $invoiceParams = [
                    'expand' => [
                        'lines.data.price.product',
                        'subscription',
                        'payment_intent'
                    ]
                ];
                $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);
                $isSubscriptionUpdateRequest = $this->getIsSubscriptionUpdateRequest($invoice, $invoice->payment_intent);

                if ($object['billing_reason'] == "subscription_update")
                {
                    // The event will arrive before the order is saved to the database. $order is likely the original order before
                    // the subscription was updated. So don't change any order state here. Use an after order saved observer instead.
                    return;
                }

                $isNewSubscriptionOrder = (!empty($object["billing_reason"]) && $object["billing_reason"] == "subscription_create");

                switch ($paymentMethod)
                {
                    case 'stripe_payments':
                    case 'stripe_payments_express':

                        $subscriptionId = $invoice->subscription->id;
                        $subscriptionModel = $this->subscriptionFactory->create()->load($subscriptionId, "subscription_id");
                        $subscriptionModel->initFrom($invoice->subscription, $order)->setIsNew(false)->save();

                        $updateParams = [];
                        if (empty($invoice->subscription->default_payment_method) && !empty($invoice->payment_intent->payment_method))
                            $updateParams["default_payment_method"] = $invoice->payment_intent->payment_method;

                        if (empty($invoice->subscription->metadata->{"Order #"}))
                            $updateParams["metadata"] = ["Order #" => $order->getIncrementId()];

                        if (!empty($updateParams))
                            $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

                        if (!$isNewSubscriptionOrder)
                        {
                            try
                            {
                                // This is a recurring payment, so create a brand new order based on the original one
                                $this->recurringOrderHelper->createFromInvoiceId($invoiceId);
                            }
                            catch (\Exception $e)
                            {
                                $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                                throw $e;
                            }
                        }

                        break;

                    case 'stripe_payments_checkout':

                        if ($isNewSubscriptionOrder)
                        {
                            if (!empty($invoice->payment_intent))
                            {
                                // With Stripe Checkout, the Payment Intent description and metadata can be set only
                                // after the payment intent is confirmed and the subscription is created.
                                $quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());
                                $params = $this->paymentIntentFactory->create()->getParamsFrom($quote, $order, $invoice->payment_intent->payment_method);
                                $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params, $invoice->payment_intent, $filter = ["description", "metadata"]);
                                $this->config->getStripeClient()->paymentIntents->update($invoice->payment_intent->id, $updateParams);
                                $invoice = $this->config->getStripeClient()->invoices->retrieve($invoiceId, $invoiceParams);
                            }
                            else if ($this->paymentsHelper->hasOnlyTrialSubscriptionsIn($order->getAllItems()))
                            {
                                // No charge.succeeded event will arrive, so ready the order for fulfillment here.
                                $order = $this->paymentsHelper->loadOrderById($order->getId()); // Refresh in case another event is mutating the order
                                if (!$order->getEmailSent())
                                {
                                    $this->paymentsHelper->sendNewOrderEmailFor($order, true);
                                }
                                if ($order->getInvoiceCollection()->getSize() < 1)
                                {
                                    $this->paymentsHelper->invoiceOrder($order, null, \Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                                }
                                $this->paymentsHelper->setProcessingState($order, __("Trial subscription started."));
                                $this->paymentsHelper->saveOrder($order);
                            }

                            if ($invoice->status == "paid")
                            {
                                $this->creditmemoHelper->refundUnderchargedOrder($order, $invoice->amount_paid, $invoice->currency);
                            }
                        }
                        else // Is recurring subscription order
                        {
                            try
                            {
                                // This is a recurring payment, so create a brand new order based on the original one
                                $this->recurringOrderHelper->createFromSubscriptionItems($invoiceId);
                            }
                            catch (\Exception $e)
                            {
                                $this->webhooksHelper->sendRecurringOrderFailedEmail($arrEvent, $e);
                                throw $e;
                            }
                        }

                        break;

                    default:
                        # code...
                        break;
                }

                break;

            case 'stripe_payments_webhook_invoice_paid':

                $order = $this->webhooksHelper->loadOrderFromEvent($arrEvent);
                $paymentMethod = $order->getPayment()->getMethod();

                if ($paymentMethod != "stripe_payments_invoice")
                    break;

                $order->getPayment()->setLastTransId($object['payment_intent'])->save();

                foreach($order->getInvoiceCollection() as $invoice)
                {
                    $invoice->setTransactionId($object['payment_intent']);
                    $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_OFFLINE);
                    $invoice->pay();
                    $this->paymentsHelper->saveInvoice($invoice);
                }

                $this->paymentsHelper->setProcessingState($order, __("The customer has paid the invoice for this order."));
                $this->paymentsHelper->saveOrder($order);

                break;

            case 'stripe_payments_webhook_invoice_payment_failed':
                //$this->paymentFailed($event);
                break;

            default:
                # code...
                break;
        }
    }

    public function addOrderCommentWithEmail($order, $comment)
    {
        if (is_string($comment))
            $comment = __($comment);

        try
        {
            $this->orderCommentSender->send($order, $notify = true, $comment);
        }
        catch (\Exception $e)
        {
            // Just ignore this case
        }

        try
        {
            $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = true);
            $this->paymentsHelper->saveOrder($order);
        }
        catch (\Exception $e)
        {
            $this->webhooksHelper->log($e->getMessage(), $e);
        }
    }

    public function addOrderComment($order, $comment)
    {
        $order->addStatusToHistory($status = false, $comment, $isCustomerNotified = false);
        $this->paymentsHelper->saveOrder($order);
    }

    public function getShippingAmount($event)
    {
        if (empty($event->data->object->lines->data))
            return 0;

        foreach ($event->data->object->lines->data as $lineItem)
        {
            if (!empty($lineItem->description) && $lineItem->description == "Shipping")
            {
                return $lineItem->amount;
            }
        }
    }

    public function getTaxAmount($event)
    {
        if (empty($event->data->object->tax))
            return 0;

        return $event->data->object->tax;
    }

    public function deduplicatePaymentMethod($object, $order)
    {
        try
        {
            if (!empty($object['customer']) && !empty($object['payment_method']))
            {
                $type = $object['payment_method_details']['type'];
                if (!empty($object['payment_method_details'][$type]['fingerprint']))
                {
                    $this->paymentsHelper->deduplicatePaymentMethod(
                        $object['customer'],
                        $object['payment_method'],
                        $type,
                        $object['payment_method_details'][$type]['fingerprint'],
                        $this->config->getStripeClient()
                    );
                }

                $paymentMethod = $this->config->getStripeClient()->paymentMethods->retrieve($object['payment_method'], []);
                if ($paymentMethod->customer) // true if the PM is saved on the customer
                {
                    // Update the billing address on the payment method if that is already attached to a customer
                    $this->config->getStripeClient()->paymentMethods->update(
                        $object['payment_method'],
                        ['billing_details' => $this->addressHelper->getStripeAddressFromMagentoAddress($order->getBillingAddress())]
                    );
                }
            }
        }
        catch (\Exception $e)
        {
            return false;
        }

        return true;
    }

    // When the customer upgrades/downgrades their subscription, the resulting payment intent does not have any metadata
    public function getIsSubscriptionUpdateRequest($invoice, $paymentIntent)
    {
        // The billing reason must be correct
        if ($invoice->billing_reason != "subscription_update")
            return false;

        // And the PI must not have previous order metadata
        if (!empty($paymentIntent->metadata->{"Order #"}))
            return false;

        return true;

        // These don't work.
        // // And in our DB, the last_updated timestamp should be within the last 6 hours
        // if (empty($invoice->subscription))
        //     return false;

        // if (is_string($invoice->subscription))
        //     $subscriptionId = $invoice->subscription;
        // else
        //     $subscriptionId = $invoice->subscription->id;

        // $subscriptionModel = $this->subscriptionFactory->create()->load($subscriptionId, "subscription_id");
        // if (!$subscriptionModel->getId())
        //     return false;

        // $lastUpdated = $subscriptionModel->getLastUpdated();
        // if (!$lastUpdated)
        //     return false;

        // $sixHours = 6 * 60 * 60;
        // if ((time() - $lastUpdated) > $sixHours)
        // {
        //     return false;
        // }

        // return true;
    }
}
