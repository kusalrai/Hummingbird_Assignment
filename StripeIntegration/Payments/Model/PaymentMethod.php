<?php

namespace StripeIntegration\Payments\Model;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NotFoundException;
use Magento\Payment\Model\InfoInterface;
use Magento\Payment\Model\MethodInterface;
use Magento\Quote\Api\Data\CartInterface;
use StripeIntegration\Payments\Helper;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;
use Magento\Payment\Observer\AbstractDataAssignObserver;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Exception\RefundOfflineException;

class PaymentMethod extends \Magento\Payment\Model\Method\Adapter
{
    protected $_code                 = "stripe_payments";

    protected $_isInitializeNeeded      = false;
    protected $_canUseForMultishipping  = false;

    public function __construct(
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Payment\Gateway\Config\ValueHandlerPoolInterface $valueHandlerPool,
        \Magento\Payment\Gateway\Data\PaymentDataObjectFactory $paymentDataObjectFactory,
        string $code,
        string $formBlockType,
        string $infoBlockType,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement,
        \StripeIntegration\Payments\Model\PaymentElementFactory $paymentElementFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Api $api,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Helper\Multishipping $multishippingHelper,
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper,
        \Magento\Payment\Gateway\Command\CommandPoolInterface $commandPool = null,
        \Magento\Payment\Gateway\Validator\ValidatorPoolInterface $validatorPool = null
    ) {
        $this->config = $config;
        $this->paymentElement = $paymentElement;
        $this->paymentElementFactory = $paymentElementFactory;
        $this->helper = $helper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->api = $api;
        $this->customer = $helper->getCustomerModel();
        $this->paymentIntent = $paymentIntent;
        $this->multishippingHelper = $multishippingHelper;
        $this->refundsHelper = $refundsHelper;

        $this->evtManager = $eventManager;

        if ($this->helper->isMultiShipping())
            $formBlockType = 'StripeIntegration\Payments\Block\Multishipping\Billing';
        else if ($this->helper->isAdmin())
            $formBlockType = 'StripeIntegration\Payments\Block\Adminhtml\Payment\Form';
        else
            $formBlockType = 'Magento\Payment\Block\Form';

        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool
        );
    }

    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);

        if ($this->config->getIsStripeAPIKeyError())
            $this->helper->dieWithError("Invalid API key provided");

        $additionalData = $data->getAdditionalData();

        $info = $this->getInfoInstance();

        $this->helper->assignPaymentData($info, $additionalData);

        return $this;
    }

    public function checkIfCartIsSupported(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        if (!$this->helper->hasTrialSubscriptions())
            return;

        if ($this->helper->hasOnlyTrialSubscriptions())
            return;

        if ($payment->getOrder()->getDiscountAmount() < 0)
        {
            // The cart includes both trial subscriptions and regular products. In the case of expiring discounts, the full discount will be applied
            // on the initial payment which is incorrect. We need to apply only the discount which applies to the regular product, without applying
            // the discount that applies on the trial subscription. Currently this is not supported.
            if ($this->subscriptionsHelper->hasExpiringDiscountCoupons())
            {
                throw new LocalizedException(__("Limited-time discount coupons cannot be applied on carts that include both regular products and trial subscriptions."));
            }
        }
    }

    public function authorize(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->checkIfCartIsSupported($payment, $amount);

        if ($amount > 0)
        {
            if ($this->subscriptionsHelper->isSubscriptionUpdate())
            {
                $this->subscriptionsHelper->updateSubscription($payment);
            }
            else if ($this->helper->isMultiShipping())
            {
                $this->doNotPay($payment);
            }
            else if ($payment->getAdditionalInformation("client_side_confirmation"))
            {
                $this->payWithClientSideConfirmation($payment, $amount);
            }
            else
            {
                $this->payWithServerSideConfirmation($payment, $amount);
            }
        }

        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $this->checkIfCartIsSupported($payment, $amount);

        if ($amount > 0)
        {
            // We get in here when the store is configured in Authorize Only mode and we are capturing a payment from the admin
            $token = $payment->getTransactionId();
            if (empty($token))
            {
                $token = $payment->getLastTransId(); // In case where the transaction was not created during the checkout, i.e. with a Stripe Webhook redirect
            }

            if ($token)
            {
                // Capture an authorized payment from the admin area

                $token = $this->helper->cleanToken($token);

                $orders = $this->helper->getOrdersByTransactionId($token);
                $quoteId = (($payment->getOrder() && $payment->getOrder()->getQuoteId()) ? $payment->getOrder()->getQuoteId() : null);
                if (count($orders) > 1 && $this->multishippingHelper->isMultishippingQuote($quoteId))
                    $this->multishippingHelper->captureOrdersFromAdminArea($orders, $token, $payment, $amount, $this->config->retryWithSavedCard());
                else
                    $this->helper->capture($token, $payment, $amount, $this->config->retryWithSavedCard());
            }
            else if ($this->subscriptionsHelper->isSubscriptionUpdate())
            {
                $this->subscriptionsHelper->updateSubscription($payment);
            }
            else if ($this->helper->isMultiShipping())
            {
                $this->doNotPay($payment);
            }
            else if ($payment->getAdditionalInformation("client_side_confirmation"))
            {
                $this->payWithClientSideConfirmation($payment, $amount);
            }
            else
            {
                $this->payWithServerSideConfirmation($payment, $amount);
            }
        }

        return $this;
    }

    public function doNotPay(\Magento\Payment\Model\InfoInterface $payment)
    {
        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setIsTransactionClosed(false); // not captured
        $payment->getOrder()->setCanSendNewEmailFlag(false);
    }

    public function payWithClientSideConfirmation(InfoInterface $payment, $amount)
    {
        // Update the payment intent by loading it from cache - the load method with update it if its different.
        $this->paymentElement->updateFromOrder($payment->getOrder(), $payment->getAdditionalInformation("token"));
        $this->paymentElement->cancelInvalidOrders($payment->getOrder());

        $paymentIntent = $this->paymentElement->getPaymentIntent();
        $setupIntent = $this->paymentElement->getSetupIntent();
        if ($paymentIntent)
        {
            $payment->setTransactionId($paymentIntent->id);
            if (!empty($paymentIntent->customer))
                $payment->setAdditionalInformation("customer_stripe_id", $paymentIntent->customer);
        }
        else if ($setupIntent)
        {
            $payment->setTransactionId("cannot_capture_subscriptions");
            if (!empty($setupIntent->customer))
                $payment->setAdditionalInformation("customer_stripe_id", $setupIntent->customer);
        }

        $payment->setIsFraudDetected(false);
        $payment->setIsTransactionPending(true); // not authorized yet
        $payment->setIsTransactionClosed(false); // not captured
        $payment->getOrder()->setCanSendNewEmailFlag(false);
        $this->helper->overrideInvoiceActionComment($payment, __("Order placed, beginning the payment process."));
    }

    public function payWithServerSideConfirmation(InfoInterface $payment, $amount)
    {
        if ($payment->getAdditionalInformation("is_recurring_subscription"))
            return $this;

        if (!$payment->getAdditionalInformation("token"))
            $this->helper->dieWithError(__("Cannot place order because a payment method was not provided."));

        $order = $payment->getOrder();
        $paymentElement = $this->paymentElementFactory->create();
        $clientSecret = $paymentElement->getClientSecret($order->getQuoteId(), $payment->getAdditionalInformation("token"), $order);
        $paymentElement->updateFromOrder($order, $payment->getAdditionalInformation("token"));

        try
        {
            $paymentElement->confirm($order); // throws SCANeededException

            if ($paymentElement->getPaymentIntent())
            {
                $this->paymentIntent->processAuthenticatedOrder($order, $paymentElement->getPaymentIntent());
            }
            else if ($setupIntent = $paymentElement->getSetupIntent())
            {
                $payment->setIsFraudDetected(false);
                $payment->setIsTransactionPending(true);
                $payment->setIsTransactionClosed(false);
                $payment->setTransactionId("cannot_capture_subscriptions");
                if (!empty($setupIntent->customer))
                    $payment->setAdditionalInformation("customer_stripe_id", $setupIntent->customer);
            }
        }
        catch (SCANeededException $e)
        {
            if ($this->helper->isAdmin())
                $this->helper->dieWithError(__("This payment method cannot be used because it requires a customer authentication. To avoid authentication in the admin area, please contact Stripe support to request access to the MOTO gate for your Stripe account."));

            // Front-end case (Payment Request API, REST API, GraphQL API), this will trigger the 3DS modal.
            $this->helper->dieWithError("Authentication Required: $clientSecret");
        }

        return $this;
    }

    public function cancel(InfoInterface $payment, $amount = null)
    {
        if ($payment->getCancelOfflineWithComment())
        {
            $this->helper->overrideCancelActionComment($payment, $payment->getCancelOfflineWithComment());
            return $this;
        }

        try
        {
            $paymentIntentId = $this->refundsHelper->getTransactionId($payment);
            $paymentIntent = $this->config->getStripeClient()->paymentIntents->retrieve($paymentIntentId, []);

            if ($this->multishippingHelper->isMultishippingPayment($paymentIntent) && $paymentIntent->status == "requires_capture")
            {
                $this->refundsHelper->refundMultishipping($paymentIntent, $payment, $amount);
            }
            else
            {
                $this->refundsHelper->refund($payment, $amount);
            }
        }
        catch (RefundOfflineException $e)
        {
            if ($this->helper->isAdmin())
            {
                $this->helper->addWarning($e->getMessage());
            }

            if ($this->refundsHelper->isCancelation($payment))
                $this->helper->overrideCancelActionComment($payment, $e->getMessage());
            else
                $this->helper->addOrderComment($e->getMessage(), $payment->getOrder());
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError(__('Could not refund payment: %1', $e->getMessage()), $e);
        }

        return $this;
    }

    public function cancelInvoice($invoice)
    {
        return $this;
    }

    public function refund(InfoInterface $payment, $amount)
    {
        $this->cancel($payment, $amount);

        return $this;
    }

    public function void(InfoInterface $payment)
    {
        $this->cancel($payment);

        return $this;
    }

    public function acceptPayment(InfoInterface $payment)
    {
        return parent::acceptPayment($payment);
    }

    public function denyPayment(InfoInterface $payment)
    {
        return parent::denyPayment($payment);
    }

    public function canCapture()
    {
        return parent::canCapture();
    }

    public function isApplePay()
    {
        $info = $this->getInfoInstance();
        if ($info)
            return $info->getAdditionalInformation("is_prapi");

        return false;
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($quote && $quote->getIsRecurringOrder())
            return true;

        if ($this->subscriptionsHelper->isSubscriptionUpdate())
            return true;

        if (!$this->config->isEnabled())
            return false;

        if ($this->helper->isAdmin())
            return parent::isAvailable($quote);

        if ($this->config->isRedirectPaymentFlow() && !$this->isApplePay() && !$this->helper->isMultiShipping())
            return false;

        return parent::isAvailable($quote);
    }

    public function getConfigPaymentAction()
    {
        $info = $this->getInfoInstance();
        if ($info && $info->getAdditionalInformation("client_side_confirmation"))
        {
            // With client side confirmations, a pending order will be created. We do not want to invoice
            // the order until the charge.succeeded event arrives back from Stripe.
            return \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE;
        }

        // Subscriptions do not support authorize only mode
        if ($this->helper->hasSubscriptions())
            return \Magento\Payment\Model\Method\AbstractMethod::ACTION_AUTHORIZE_CAPTURE;

        return parent::getConfigPaymentAction();
    }
}
