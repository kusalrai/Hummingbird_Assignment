<?php

namespace StripeIntegration\Payments\Model\RateLimiter;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Store\Model\ScopeInterface;
use Magento\Framework\Exception\LocalizedException;

class PaymentIntentLimiter extends Limiter
{
    protected $createLimits = null;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\App\State $appState
    )
    {
        $this->scopeConfig = $scopeConfig;

        $this->createLimits = $this->scopeConfig->getValue("stripe_settings/rate_limiter/pi_create", ScopeInterface::SCOPE_STORE, 0);

        parent::__construct($scopeConfig, $cache, $customerSession, $remoteAddress, $appState);
    }

    public function create(\Magento\Framework\Phrase $exceptionMessage = null)
    {
        if (!$this->isActive())
        {
            return;
        }

        $identifier = $this->getIdentifier();

        if (!$identifier)
        {
            return;
        }

        foreach ($this->createLimits as $perUnit => $limitAmount)
        {
            $amount = $this->getValue($identifier, "pi_create", $perUnit);

            if ($amount >= $limitAmount)
            {
                if ($exceptionMessage)
                {
                    throw new LocalizedException($exceptionMessage);
                }
                else
                {
                    throw new LocalizedException(__("Too many requests. Please try again later."));
                }
            }

            $this->increaseValue($identifier, "pi_create", $perUnit);
        }
    }
}
