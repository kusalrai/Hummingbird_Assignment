<?php

namespace StripeIntegration\Payments\Helper;

use Psr\Log\LoggerInterface;

class Locale
{
    public function __construct(
        \Magento\Framework\Locale\Resolver $localeResolver
    ) {
        $this->localeResolver = $localeResolver;
    }

    public function getLocale()
    {
        return $this->localeResolver->getLocale();
    }

    public function getStripeJsLocale()
    {
        $supportedValues = ["ar", "bg", "cs", "da", "de", "el", "en", "en-GB", "es", "es-419", "et", "fi", "fr", "fr-CA", "he", "hu", "id", "it", "ja", "lt", "lv", "ms", "mt", "nb", "nl", "pl", "pt-BR", "pt", "ro", "ru", "sk", "sl", "sv", "tr", "zh", "zh-HK", "zh-TW"];

        return $this->resolveSupportedLocale($supportedValues);
    }

    public function getStripeCheckoutLocale()
    {
        $supportedValues = ['bg', 'cs', 'da', 'de', 'el', 'en', 'en-GB', 'es', 'es-419', 'et', 'fi', 'fil', 'fr', 'fr-CA', 'hr', 'hu', 'id', 'it', 'ja', 'ko', 'lt', 'lv', 'ms', 'mt', 'nb', 'nl', 'pl', 'pt', 'pt-BR', 'ro', 'ru', 'sk', 'sl', 'sv', 'th', 'tr', 'vi', 'zh', 'zh-HK', 'zh-TW'];

        return $this->resolveSupportedLocale($supportedValues);
    }

    protected function resolveSupportedLocale($supportedValues)
    {
        $locale = $this->localeResolver->getLocale();
        if (empty($locale))
            return "auto";

        switch ($locale)
        {
            case "zh_Hans_CN":
                $locale = "zh";
                break;
            case "zh_Hant_HK":
                $locale = "zh-HK";
                break;
            case "zh_Hant_TW":
                $locale = "zh-TW";
                break;
            default:
                break;
        }

        $hyphenLocale = str_replace("_", "-", $locale);
        if (in_array($hyphenLocale, $supportedValues))
            return $hyphenLocale;

        $lang = strstr($locale, '_', true);
        if (in_array($lang, $supportedValues))
            return $lang;

        return "auto";
    }
}
