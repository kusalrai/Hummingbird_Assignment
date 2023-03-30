<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class InitParams
{
    protected $setupIntents = [];

    public function __construct(
        \Magento\Framework\Serialize\SerializerInterface $serializer,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\Locale $localeHelper,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Helper\ExpressHelper $expressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntent,
        \StripeIntegration\Payments\Model\PaymentElement $paymentElement
    ) {
        $this->serializer = $serializer;
        $this->helper = $helper;
        $this->localeHelper = $localeHelper;
        $this->addressHelper = $addressHelper;
        $this->expressHelper = $expressHelper;
        $this->config = $config;
        $this->paymentIntent = $paymentIntent;
        $this->paymentElement = $paymentElement;
        $this->customer = $helper->getCustomerModel();
    }

    public function getCheckoutParams()
    {
        if (!$this->config->isEnabled())
        {
            $params = [];
        }
        else if ($this->helper->isMultiShipping()) // Called by the UIConfigProvider
        {
            return $this->getMultishippingParams();
        }
        else
        {
            $params = [
                "apiKey" => $this->config->getPublishableKey(),
                "locale" => $this->localeHelper->getStripeJsLocale(),
                "appInfo" => $this->config->getAppInfo(true)
            ];

            // When the wallet button is enabled at the checkout, we do not want to also display it inside the Payment Element, so we disable it there.
            if ($this->expressHelper->isEnabled("checkout_page"))
            {
                $params["wallets"] = [
                    "applePay" => "never",
                    "googlePay" => "never"
                ];
            }
            else
                $params["wallets"] = null;
        }

        return $this->serializer->serialize($params);
    }

    public function getAdminParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true)
        ];

        return $this->serializer->serialize($params);
    }

    public function getMultishippingParams()
    {
        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true),
            "savedMethods" => $this->customer->getSavedPaymentMethods(null, true)
        ];

        return $this->serializer->serialize($params);
    }

    public function getMyPaymentMethodsParams($customerId)
    {
        if (!$this->config->isEnabled())
            return $this->serializer->serialize([]);

        $params = [
            "apiKey" => $this->config->getPublishableKey(),
            "locale" => $this->localeHelper->getStripeJsLocale(),
            "appInfo" => $this->config->getAppInfo(true)
        ];

        $stripe = $this->config->getStripeClient();

        if (isset($this->setupIntents[$customerId]))
        {
            $setupIntent = $this->setupIntents[$customerId];
        }
        else
        {
            $setupIntent = $stripe->setupIntents->create([
                'customer' => $customerId,
                'usage' => 'on_session',
                // Auto-PMs are not supported by SetupIntents yet
                // 'automatic_payment_methods' => [
                //     'enabled' => true
                // ],
            ]);
        }

        $params["clientSecret"] = $setupIntent->client_secret;
        $params["returnUrl"] = $this->helper->getUrl('stripe/customer/paymentmethods');

        return $this->serializer->serialize($params);
    }
}
