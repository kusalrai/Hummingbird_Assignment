<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class PaymentMethod
{
    protected $methodDetails = [];
    protected $themeModel = null;
    const CAN_BE_SAVED_ON_SESSION = [
        'acss_debit',
        'au_becs_debit',
        'boleto',
        'card',
        'sepa_debit',
        'us_bank_account'
    ];
    const CAN_BE_SAVED_OFF_SESSION = [ // Do not add methods that can be saved on_session here, see Model/PaymentIntent.php::getPaymentMethodOptions()
        'bancontact',
        'ideal',
        'sofort'
    ];
    const SUPPORTS_SUBSCRIPTIONS = [
        'card',
        'sepa_debit',
        'us_bank_account'
    ];
    const SETUP_INTENT_PAYMENT_METHOD_OPTIONS = [
        'acss_debit',
        'card',
        'sepa_debit',
        'us_bank_account'
    ];
    const CAN_AUTHORIZE_ONLY = [
        'card',
        'link',
        'afterpay_clearpay',
        'klarna'
    ];
    const REQUIRES_VOUCHER_PAYMENT = [
        'boleto',
        'oxxo',
        'konbini'
    ];

    public function __construct(
        \Magento\Framework\App\RequestInterface $request,
        \Magento\Framework\View\Asset\Repository $assetRepo,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Magento\Framework\View\Design\Theme\ThemeProviderInterface $themeProvider,
        \StripeIntegration\Payments\Helper\Data $dataHelper
    ) {
        $this->request = $request;
        $this->assetRepo = $assetRepo;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->themeProvider = $themeProvider;
        $this->dataHelper = $dataHelper;
    }

    public function getCardIcon($brand)
    {
        $icon = $this->getPaymentMethodIcon($brand);
        if ($icon)
            return $icon;

        return $this->getPaymentMethodIcon('generic');
    }

    public function getCardLabel($card, $hideLast4 = false)
    {
        if (!empty($card->last4) && !$hideLast4)
            return __("•••• %1", $card->last4);

        if (!empty($card->brand))
            return $this->getCardName($card->brand);

        return __("Card");
    }

    protected function getCardName($brand)
    {
        if (empty($brand))
            return "Card";

        $details = $this->getPaymentMethodDetails();
        if (isset($details[$brand]))
            return $details[$brand]['name'];

        return ucfirst($brand);
    }

    public function getIcon($method, $format = null)
    {
        $type = $method->type;

        $defaultIcon = $this->getPaymentMethodIcon($type);
        if ($defaultIcon)
        {
            $icon = $defaultIcon;
        }
        else if ($type == "card" && !empty($method->card->brand))
        {
            $icon = $this->getCardIcon($method->card->brand);
        }
        else
        {
            $icon = $this->getPaymentMethodIcon("bank");
        }

        if ($format)
            $icon = str_replace(".svg", ".$format", $icon);

        return $icon;
    }

    public function getLabel($method)
    {
        if (empty($method->type))
            return null;

        $methodName = $this->getPaymentMethodName($method->type);
        $details = $method->{$method->type};

        switch ($method->type)
        {
            case "card":
                return $this->getCardLabel($method->card);
            case "sepa_debit":
            case "au_becs_debit":
            case "acss_debit":
                return __("%1 •••• %2", $methodName, $details->last4);
            case 'boleto':
                return __("%1 - %2", $methodName, $details->tax_id);
            default:
                return str_replace("_", " ", ucfirst($methodName));
        }
    }

    public function getPaymentMethodIcon($code)
    {
        $details = $this->getPaymentMethodDetails();
        if (isset($details[$code]))
            return $details[$code]['icon'];

        return null;
    }

    public function getPaymentMethodName($code)
    {
        $details = $this->getPaymentMethodDetails();

        if (isset($details[$code]))
            return $details[$code]['name'];

        return ucwords(str_replace("_", " ", $code));
    }

    public function getCVCIcon()
    {
        return $this->getViewFileUrl("StripeIntegration_Payments::img/icons/cvc.svg");
    }

    public function getPaymentMethodDetails()
    {
        if (!empty($this->methodDetails))
            return $this->methodDetails;

        return $this->methodDetails = [
            // APMs
            'acss_debit' => [
                'name' => "ACSS Direct Debit / Canadian PADs",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'afterpay_clearpay' => [
                'name' => "Afterpay / Clearpay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/afterpay_clearpay.svg")
            ],
            'alipay' => [
                'name' => "Alipay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/alipay.svg")
            ],
            'bacs_debit' => [
                'name' => "BACS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bacs_debit.svg")
            ],
            'au_becs_debit' => [
                'name' => "BECS Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'bancontact' => [
                'name' => "Bancontact",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bancontact.svg")
            ],
            'boleto' => [
                'name' => "Boleto",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/boleto.svg")
            ],
            'eps' => [
                'name' => 'EPS',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/eps.svg")
            ],
            'fpx' => [
                'name' => "FPX",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/fpx.svg")
            ],
            'giropay' => [
                'name' => "Giropay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/giropay.svg")
            ],
            'grabpay' => [
                'name' => "GrabPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/grabpay.svg")
            ],
            'ideal' => [
                'name' => "iDEAL",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/ideal.svg")
            ],
            'klarna' => [
                'name' => "Klarna",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'konbini' => [
                'name' => "Konbini",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/konbini.svg")
            ],
            'paypal' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paypal.svg")
            ],
            'multibanco' => [
                'name' => "Multibanco",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/multibanco.svg")
            ],
            'p24' => [
                'name' => "P24",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/p24.svg")
            ],
            'sepa_debit' => [
                'name' => "SEPA Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_debit.svg")
            ],
            'sepa_credit' => [
                'name' => "SEPA Credit Transfer",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/sepa_credit.svg")
            ],
            'sofort' => [
                'name' => "SOFORT",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/klarna.svg")
            ],
            'wechat' => [
                'name' => "WeChat Pay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/wechat.svg")
            ],
            'ach_debit' => [
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'us_bank_account' => [ // ACHv2
                'name' => "ACH Direct Debit",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],
            'oxxo' => [
                'name' => "OXXO",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/oxxo.svg")
            ],
            'paynow' => [
                'name' => "PayNow",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/paynow.svg")
            ],
            'link' => [
                'name' => 'Link',
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/link.svg")
            ],
            'bank' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/methods/bank.svg")
            ],

            // Cards
            'amex' => [
                'name' => "American Express",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/amex.svg")
            ],
            'cartes_bancaires' => [
                'name' => "Cartes Bancaires",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/cartes_bancaires.svg")
            ],
            'diners' => [
                'name' => "Diners Club",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/diners.svg")
            ],
            'discover' => [
                'name' => "Discover",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/discover.svg")
            ],
            'generic' => [
                'name' => "",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/generic.svg")
            ],
            'jcb' => [
                'name' => "JCB",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/jcb.svg")
            ],
            'mastercard' => [
                'name' => "MasterCard",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/mastercard.svg")
            ],
            'visa' => [
                'name' => "Visa",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/visa.svg")
            ],
            'unionpay' => [
                'name' => "UnionPay",
                'icon' => $this->getViewFileUrl("StripeIntegration_Payments::img/cards/unionpay.svg")
            ]
        ];
    }

    public function isCard1NewerThanCard2($card1expMonth, $card1expYear, $card2expMonth, $card2expYear)
    {

    }

    public function getPaymentMethodLabel($method)
    {
        $type = $method->type;
        $methodName = $this->getPaymentMethodName($type);
        $details = $method->{$type};

        if ($type == "card")
        {
            return $this->getCardLabel($details);
        }
        else if (isset($details->last4))
        {
            return __("%1 •••• %2", $methodName, $details->last4);
        }
        else if (isset($details->tax_id)) // Boleto
        {
            return __("%1 - %2", $methodName, $details->tax_id);
        }
        else
        {
            return null;
        }
    }

    public function formatPaymentMethods($methods)
    {
        $savedMethods = [];

        if ($this->dataHelper->getConfigData("payment/stripe_payments/cvc_code") == "new_saved_cards")
        {
            $cvc = 1;
        }
        else
        {
            $cvc = 0;
        }

        foreach ($methods as $type => $methodList)
        {
            $methodName = $this->getPaymentMethodName($type);

            switch ($type)
            {
                case "card":
                    foreach ($methodList as $method)
                    {
                        $details = $method->card;
                        $key = $details->fingerprint;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $details->fingerprint,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $this->getCardIcon($details->brand),
                            "cvc" => $cvc,
                            "brand" => $details->brand,
                            "exp_month" => $details->exp_month,
                            "exp_year" => $details->exp_year,
                        ];
                    }
                    break;
                default:
                    foreach ($methodList as $method)
                    {
                        $details = $method->{$type};
                        if (empty($details->fingerprint) || empty($details->last4))
                            continue;

                        $icon = $this->getPaymentMethodIcon($type);
                        if (!$icon)
                            $icon = $this->getPaymentMethodIcon("bank");

                        $key = $details->fingerprint;

                        if (isset($savedMethods[$key]) && $savedMethods[$key]['created'] > $method->created)
                            continue;

                        $label = $this->getPaymentMethodLabel($method);
                        if (empty($label))
                            continue;

                        $savedMethods[$key] = [
                            "id" => $method->id,
                            "created" => $method->created,
                            "type" => $type,
                            "fingerprint" => $details->fingerprint,
                            "label" => $label,
                            "value" => $method->id,
                            "icon" => $icon
                        ];
                    }
                    break;
            }
        }

        return $savedMethods;
    }

    protected function getViewFileUrl($fileId)
    {
        try
        {
            $params = [
                '_secure' => $this->request->isSecure(),
                'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                'themeModel' => $this->getThemeModel()
            ];
            return $this->assetRepo->getUrlWithParams($fileId, $params);
        }
        catch (LocalizedException $e)
        {
            return null;
        }
    }

    protected function getThemeModel()
    {
        if ($this->themeModel)
            return $this->themeModel;

        $themeId = $this->scopeConfig->getValue(
            \Magento\Framework\View\DesignInterface::XML_PATH_THEME_ID,
            \Magento\Store\Model\ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore()->getId()
        );

        $this->themeModel = $this->themeProvider->getThemeById($themeId);

        return $this->themeModel;
    }

}
