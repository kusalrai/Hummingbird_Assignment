<?php

namespace StripeIntegration\Payments\Observer;

use Magento\Framework\Event\ObserverInterface;
use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\WebhookException;

class CouponEditObserver implements ObserverInterface
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Coupon $coupon
    )
    {
        $this->helper = $helper;
        $this->config = $config;
        $this->coupon = $coupon;
    }

    public function execute(\Magento\Framework\Event\Observer $observer)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        $rule = $observer->getRule();
        $couponDuration = $rule->getCouponDuration();
        $couponMonths = $rule->getCouponMonths();

        if (empty($couponDuration))
            return;

        $this->coupon->load($rule->getId(), 'rule_id');
        if (!$this->coupon->getId())
            $this->coupon->setRuleId($rule->getId());

        switch ($couponDuration)
        {
            case 'forever':
                if ($this->coupon->getId())
                    $this->coupon->delete();
                break;

            case 'once':
                $this->coupon->setCouponDuration('once');
                $this->coupon->setCouponMonths(0);
                $this->coupon->save();
                break;

            case 'repeating':
                if (!is_numeric($couponMonths))
                    $this->helper->dieWithError(__("You have specified a coupon duration of Multiple Months, but you did not enter a valid months number."));

                $this->coupon->setCouponDuration('repeating');
                $this->coupon->setCouponMonths($couponMonths);
                $this->coupon->save();
                break;

            default:
                break;
        }
    }
}
