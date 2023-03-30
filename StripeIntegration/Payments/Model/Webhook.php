<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception;

class Webhook extends \Magento\Framework\Model\AbstractModel
{
    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\ResourceModel\Webhook');
    }

    public function pong()
    {
        $this->setLastEvent(time());
        return $this;
    }

    public function activate()
    {
        $this->setActive(1);
        return $this;
    }
}
