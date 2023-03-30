<?php

namespace StripeIntegration\Payments\Model\RateLimiter;

class Limiter
{
    protected $customerSession;
    protected $cache;
    protected $remoteAddress;
    protected $isActive;

    public function __construct(
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Framework\HTTP\PhpEnvironment\RemoteAddress $remoteAddress,
        \Magento\Framework\App\State $appState
    )
    {
        $this->scopeConfig = $scopeConfig;
        $this->cache = $cache;
        $this->customerSession = $customerSession;
        $this->remoteAddress = $remoteAddress;
        $this->isActive = $this->scopeConfig->getValue("stripe_settings/rate_limiter/active", \Magento\Store\Model\ScopeInterface::SCOPE_STORE, 0);
        $this->appState = $appState;
    }

    public function isActive()
    {
        if (!$this->isActive)
            return false;

        if ($this->appState->getMode() == \Magento\Framework\App\State::MODE_DEVELOPER)
            return false;

        return true;
    }

    public function getIdentifier()
    {
        // We work with logged in customers and IP addresses only. We don't want to use the checkout
        // session or cart ID because these can easily be recreated.
        if ($this->customerSession->isLoggedIn() && is_numeric($this->customerSession->getCustomerId()))
        {
            return "customer_id_" . $this->customerSession->getCustomerId();
        }
        else if ($this->remoteAddress->getRemoteAddress())
        {
            return "ip_address_" . $this->remoteAddress->getRemoteAddress();
        }
        else
        {
            return null;
        }
    }

    protected function getKey($identifier, $scope, $unit)
    {
        return "{$identifier}_{$scope}_{$unit}";
    }

    public function getValue($identifier, $scope, $unit)
    {
        $key = $this->getKey($identifier, $scope, $unit);
        return $this->cache->load($key);
    }

    public function increaseValue($identifier, $scope, $unit)
    {
        $key = $this->getKey($identifier, $scope, $unit);
        $value = $this->getValue($identifier, $scope, $unit);

        if (!is_numeric($value))
        {
            $value = 1;
        }
        else
        {
            $value++;
        }

        $this->cache->save($value, $key, $tags = ["stripe_payments"], $lifetime = $this->getLifetime($unit));

        return $value;
    }

    public function getLifetime($unit)
    {
        switch ($unit)
        {
            case "per_minute":
                return 60;
            case "per_hour":
                return 60 * 60;
            case "per_day":
                return 60 * 60 * 24;
            case "per_week":
                return 60 * 60 * 24 * 7;
        }

        // Default is per day
        return 60 * 60 * 24;
    }
}
