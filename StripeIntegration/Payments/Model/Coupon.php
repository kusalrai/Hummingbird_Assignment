<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

class Coupon extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Coupon');
    }

    public function duration()
    {
        switch ($this->getCouponDuration()) {
            case 'once':
            case 'repeating':
                return $this->getCouponDuration();

            default:
                return 'forever';
        }
    }

    public function months()
    {
        if ($this->duration() == 'repeating' && is_numeric($this->getCouponMonths()) && $this->getCouponMonths() > 0)
            return $this->getCouponMonths();

        return null;
    }

    public function expires()
    {
        return $this->duration() != "forever";
    }
}
