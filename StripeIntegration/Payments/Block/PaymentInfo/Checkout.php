<?php

namespace StripeIntegration\Payments\Block\PaymentInfo;

use Magento\Framework\Phrase;
use StripeIntegration\Payments\Gateway\Response\FraudHandler;
use StripeIntegration\Payments\Helper\Logger;

class Checkout extends \Magento\Payment\Block\ConfigurableInfo
{
    protected $_template = 'paymentInfo/checkout.phtml';

    public $charges = null;
    public $totalCharges = 0;
    public $charge = null;
    public $cards = array();
    public $subscription = null;
    public $checkoutSession = null;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Payment\Gateway\ConfigInterface $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptions,
        \StripeIntegration\Payments\Model\Config $paymentsConfig,
        \StripeIntegration\Payments\Helper\Api $api,
        \Magento\Directory\Model\Country $country,
        \Magento\Payment\Model\Info $info,
        \Magento\Framework\Registry $registry,
        array $data = []
    ) {
        parent::__construct($context, $config, $data);

        $this->helper = $helper;
        $this->subscriptions = $subscriptions;
        $this->paymentsConfig = $paymentsConfig;
        $this->api = $api;
        $this->country = $country;
        $this->info = $info;
        $this->registry = $registry;
        $this->paymentMethodHelper = $paymentMethodHelper;
    }

    public function getFormattedAmount()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (empty($checkoutSession->amount_total))
            return '';

        return $this->helper->formatStripePrice($checkoutSession->amount_total, $checkoutSession->currency);
    }

    public function getFormattedSubscriptionAmount()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription->plan))
            return '';

        return $this->subscriptions->formatInterval(
            $subscription->plan->amount,
            $subscription->plan->currency,
            $subscription->plan->interval_count,
            $subscription->plan->interval
        );
    }

    public function getPaymentMethod()
    {
        $checkoutSession = $this->getCheckoutSession();
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->payment_method->type))
            return $paymentIntent->payment_method;
        else if (!empty($checkoutSession->subscription->default_payment_method->type))
            return $checkoutSession->subscription->default_payment_method;

        return null;
    }

    public function getPaymentMethodCode()
    {
        $method = $this->getPaymentMethod();

        if (!empty($method->type))
            return $method->type;

        return null;
    }

    public function getPaymentMethodName($hideLast4 = false)
    {
        $paymentMethodCode = $this->getPaymentMethodCode();

        switch ($paymentMethodCode)
        {
            case "card":
                $method = $this->getPaymentMethod();
                return $this->paymentMethodHelper->getCardLabel($method->card, $hideLast4);
            default:
                return $this->paymentMethodHelper->getPaymentMethodName($paymentMethodCode);
        }
    }

    public function getPaymentMethodIconUrl($format = null)
    {
        $method = $this->getPaymentMethod();

        if (!$method)
            return null;

        return $this->paymentMethodHelper->getIcon($method, $format);
    }

    public function getCheckoutSession()
    {
        if ($this->checkoutSession)
            return $this->checkoutSession;

        try
        {
            $sessionId = $this->getInfo()->getAdditionalInformation("checkout_session_id");
            $checkoutSession = $this->paymentsConfig->getStripeClient()->checkout->sessions->retrieve($sessionId, [
                'expand' => [
                    'payment_intent',
                    'payment_intent.payment_method',
                    'subscription',
                    'subscription.default_payment_method',
                    'subscription.latest_invoice.payment_intent'
                ]
            ]);
        }
        catch (\Exception $e)
        {
            return null;
        }

        return $this->checkoutSession = $checkoutSession;
    }

    public function getPaymentIntent()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (!empty($checkoutSession->payment_intent))
            return $checkoutSession->payment_intent;

        if (!empty($checkoutSession->subscription->latest_invoice->payment_intent))
            return $checkoutSession->subscription->latest_invoice->payment_intent;

        return null;
    }

    public function getPaymentStatus()
    {
        $checkoutSession = $this->getCheckoutSession();
        $paymentIntent = $this->getPaymentIntent();

        if (empty($paymentIntent) && empty($checkoutSession->subscription))
            return "pending";

        return $this->getPaymentIntentStatus($paymentIntent);
    }

    public function getPaymentStatusName()
    {
        $status = $this->getPaymentStatus();
        return ucfirst(str_replace("_", " ", $status));
    }

    public function getSubscriptionStatus()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription))
            return null;

        return $subscription->status;
    }

    public function getSubscriptionStatusName()
    {
        $subscription = $this->getSubscription();

        if (empty($subscription))
            return null;

        if ($subscription->status == "trialing")
            return __("Trial ends %1", date("j M", $subscription->trial_end));

        return ucfirst($subscription->status);
    }

    public function getPaymentIntentStatus($paymentIntent)
    {
        if (empty($paymentIntent->status))
            return null;

        switch ($paymentIntent->status)
        {
            case "requires_payment_method":
            case "requires_confirmation":
            case "requires_action":
            case "processing":
                return "pending";
            case "requires_capture":
                return "uncaptured";
            case "canceled":
                if (!empty($paymentIntent->charges->data[0]->failure_code))
                    return "failed";
                else
                    return "canceled";
            case "succeeded":
                if ($paymentIntent->charges->data[0]->refunded)
                    return "refunded";
                else if ($paymentIntent->charges->data[0]->amount_refunded > 0)
                    return "partial_refund";
                else
                    return "succeeded";
            default:
                return $paymentIntent->status;
        }
    }

    public function getSubscription()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (!empty($checkoutSession->subscription))
            return $checkoutSession->subscription;

        return null;
    }

    public function getCard()
    {
        $method = $this->getPaymentMethod();

        if (!empty($method->card))
            return $method->card;

        return null;
    }

    public function getRiskLevelCode()
    {
        $charge = $this->getCharge();

        if (isset($charge->outcome->risk_level))
            return $charge->outcome->risk_level;

        return '';
    }

    public function getRiskScore()
    {
        $charge = $this->getCharge();

        if (isset($charge->outcome->risk_score))
            return $charge->outcome->risk_score;

        return null;
    }

    public function getRiskEvaluation()
    {
        $risk = $this->getRiskLevelCode();
        return ucfirst(str_replace("_", " ", $risk));
    }

    public function isStripeMethod()
    {
        $method = $this->getMethod()->getMethod();

        if (strpos($method, "stripe_payments") !== 0 || $method == "stripe_payments_invoice")
            return false;

        return true;
    }

    public function getCharge()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (!empty($paymentIntent->charges->data[0]))
            return $paymentIntent->charges->data[0];

        return null;
    }

    public function retrieveCharge($chargeId)
    {
        try
        {
            $token = $this->helper->cleanToken($chargeId);

            return $this->api->retrieveCharge($token);
        }
        catch (\Exception $e)
        {
            return false;
        }
    }

    public function getCustomerId()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (isset($checkoutSession->customer) && !empty($checkoutSession->customer))
            return $checkoutSession->customer;

        return null;
    }

    public function getPaymentId()
    {
        $paymentIntent = $this->getPaymentIntent();

        if (isset($paymentIntent->id))
            return $paymentIntent->id;

        return null;
    }

    public function getTransactionId()
    {
        $transactionId = $this->getInfo()->getLastTransId();
        return $this->helper->cleanToken($transactionId);
    }

    public function getMode()
    {
        $checkoutSession = $this->getCheckoutSession();

        if (empty($checkoutSession->livemode) || $checkoutSession->livemode)
            return "";

        return "test/";
    }

    public function getTitle()
    {
        $info = $this->getInfo();

        // Payment info block in admin area
        if ($info->getAdditionalInformation('payment_location'))
            return __($info->getAdditionalInformation('payment_location'));

        // Admin area: For legacy orders which did not have payment_location
        if ($info->getAdditionalInformation('is_prapi'))
        {
            $type = $info->getAdditionalInformation("prapi_title");
            if ($type)
                return __("%1 via Stripe", $type);

            return __("Wallet payment via Stripe");
        }

        return $this->getMethod()->getTitle();
    }

    public function getOXXOVoucherLink()
    {
        return null;
    }

    public function isSetupIntent()
    {
        $transactionId = $this->getTransactionId();
        if (!empty($transactionId) && strpos($transactionId, "seti_") === 0)
            return true;

        return false;
    }

    public function isLegacyPaymentMethod()
    {
        $transactionId = $this->getTransactionId();
        if (!empty($transactionId) && (strpos($transactionId, "src_") !== false || strpos($transactionId, "ch_") !== false))
            return true;

        return false;
    }
}
