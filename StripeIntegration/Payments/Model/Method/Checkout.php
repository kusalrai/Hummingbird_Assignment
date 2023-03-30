<?php

namespace StripeIntegration\Payments\Model\Method;

use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Model\InfoInterface;
use StripeIntegration\Payments\Helper;
use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Exception\CouldNotSaveException;

class Checkout extends \Magento\Payment\Model\Method\AbstractMethod
{
    const METHOD_CODE = 'stripe_payments_checkout';
    protected $_code = self::METHOD_CODE;
    protected $type = 'stripe_checkout';

    // protected $_formBlockType = 'StripeIntegration\Payments\Block\Method\Checkout';
    protected $_infoBlockType = 'StripeIntegration\Payments\Block\PaymentInfo\Checkout';

    protected $_canAuthorize = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canCaptureOnce = true;
    protected $_canRefund = true;
    protected $_canRefundInvoicePartial = true;
    protected $_isGateway = true;
    protected $_isInitializeNeeded = true;
    protected $_canVoid = true;
    protected $_canUseInternal = false;
    protected $_canFetchTransactionInfo = true;
    protected $_canUseForMultishipping  = false;
    protected $_canCancelInvoice = true;
    protected $_canUseCheckout = true;
    protected $_canSaveCc = false;

    protected $stripeCustomer = null;

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\UrlInterface $urlBuilder,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Tax\Helper\Data $taxHelper,
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Api $api,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\Stripe\CouponFactory $couponFactory,
        \StripeIntegration\Payments\Model\CheckoutSessionFactory $checkoutSessionFactory,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\CheckoutSession $checkoutSessionHelper,
        \StripeIntegration\Payments\Helper\Refunds $refundsHelper,
        \Magento\Payment\Model\Method\Logger $logger,
        \Magento\Checkout\Helper\Data $checkoutHelper,
        \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
        \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            $resource,
            $resourceCollection,
            $data
        );

        $this->cache = $context->getCacheManager();
        $this->urlBuilder = $urlBuilder;
        $this->storeManager = $storeManager;
        $this->taxHelper = $taxHelper;

        $this->config = $config;
        $this->helper = $helper;
        $this->api = $api;
        $this->paymentIntent = $paymentIntent;
        $this->customer = $helper->getCustomerModel();
        $this->logger = $logger;
        $this->request = $request;
        $this->checkoutHelper = $checkoutHelper;
        $this->scopeConfig = $scopeConfig;
        $this->couponFactory = $couponFactory;
        $this->checkoutSessionFactory = $checkoutSessionFactory;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->localeHelper = $localeHelper;
        $this->checkoutSessionHelper = $checkoutSessionHelper;
        $this->refundsHelper = $refundsHelper;
    }

    public function adjustParamsForMethod(&$params, $payment, $order, $quote)
    {
        // Overwrite this method to specify custom params for this method
    }

    public function reset()
    {
        $this->stripeCustomer = null;
        $session = $this->checkoutHelper->getCheckout();
        $session->setStripePaymentsCheckoutSessionId(null);
    }

    public function initialize($paymentAction, $stateObject)
    {
        $session = $this->checkoutHelper->getCheckout();
        $info = $this->getInfoInstance();
        $this->order = $order = $info->getOrder();
        $quote = $this->helper->getQuote();
        $this->reset();

        // We don't want to send an order email until the payment is collected asynchronously
        $order->setCanSendNewEmailFlag(false);

        $checkoutSession = $this->checkoutSessionHelper->loadFromQuote($quote);
        $params = $this->checkoutSessionHelper->getSessionParamsForOrder($order);

        $this->adjustParamsForMethod($params, $info, $order, $quote);

        try {
            if (!$checkoutSession || $this->checkoutSessionHelper->hasChanged($checkoutSession, $params))
            {
                $this->checkoutSessionHelper->cancel($checkoutSession);
                $checkoutSession = $this->checkoutSessionHelper->create($params, $quote);
            }
            else if (!empty($params["payment_intent_data"]))
            {
                $updateParams = $this->checkoutSessionHelper->getPaymentIntentUpdateParams($params["payment_intent_data"], $checkoutSession->payment_intent);
                if (!empty($checkoutSession->payment_intent->id))
                    $this->config->getStripeClient()->paymentIntents->update($checkoutSession->payment_intent->id, $updateParams);
            }

            $info->setAdditionalInformation("checkout_session_id", $checkoutSession->id);
            $session->setStripePaymentsCheckoutSessionId($checkoutSession->id);
            $order->getPayment()
                ->setIsTransactionClosed(0)
                ->setIsTransactionPending(true);

            $state = \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT;
            $status = $order->getConfig()->getStateDefaultStatus($state);
            $comment = __("The customer was redirected for payment processing. The payment is pending.");
            $order->setState($state)
                ->addStatusToHistory($status, $comment, $isCustomerNotified = false);

            $checkoutSessionModel = $this->checkoutSessionFactory->create()->load($checkoutSession->id, 'checkout_session_id');
            if (!$checkoutSessionModel->getId())
            {
                $checkoutSessionModel->setQuoteId($order->getQuoteId());
                $checkoutSessionModel->setCheckoutSessionId($checkoutSession->id);
            }

            $checkoutSessionModel->setOrderIncrementId($order->getIncrementId());
            $checkoutSessionModel->save();
        }
        catch (\Stripe\Exception\CardException $e)
        {
            throw new LocalizedException(__($e->getMessage()));
        }
        catch (\Exception $e)
        {
            if (strstr($e->getMessage(), 'Invalid country') !== false) {
                throw new LocalizedException(__('Sorry, this payment method is not available in your country.'));
            }
            throw new LocalizedException(__($e->getMessage()));
        }

        $info->setAdditionalInformation("payment_location", "Redirect flow");

        return $this;
    }

    public function capture(\Magento\Payment\Model\InfoInterface $payment, $amount)
    {
        $transactionId = $this->checkoutSessionHelper->getLastTransactionId($payment);

        if (!$transactionId)
            throw new LocalizedException(__('Sorry, it is not possible to invoice this order because the payment is still pending.'));

        try
        {
            $this->helper->capture($transactionId, $payment, $amount, $this->config->retryWithSavedCard());
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }

        return parent::capture($payment, $amount);
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

    public function getTitle()
    {
        return $this->config->getConfigData("title");
    }

    public function isEnabled($quote)
    {
        return $this->config->isEnabled() &&
            $this->config->isRedirectPaymentFlow() &&
            !$this->helper->isAdmin() &&
            !$this->helper->isMultiShipping() &&
            !$this->subscriptionsHelper->isSubscriptionUpdate();
    }

    public function isAvailable(\Magento\Quote\Api\Data\CartInterface $quote = null)
    {
        if ($this->helper->isRecurringOrder($this))
            return true;

        if (!$this->isEnabled($quote))
            return false;

        return parent::isAvailable($quote);
    }

    public function cancel(\Magento\Payment\Model\InfoInterface $payment, $amount = null)
    {
        try
        {
            $this->refundsHelper->refund($payment, $amount);
        }
        catch (\Exception $e)
        {
            $this->helper->dieWithError($e->getMessage());
        }

        return $this;
    }

    public function getConfigPaymentAction()
    {
        return $this->config->getConfigData('payment_action');
    }

    public function canEdit()
    {
        return false;
    }
}
