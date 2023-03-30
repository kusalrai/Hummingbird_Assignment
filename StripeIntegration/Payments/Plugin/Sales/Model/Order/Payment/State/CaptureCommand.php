<?php

namespace StripeIntegration\Payments\Plugin\Sales\Model\Order\Payment\State;

class CaptureCommand
{
    public function aroundExecute($subject, \Closure $proceed, \Magento\Sales\Api\Data\OrderPaymentInterface $payment, $amount, \Magento\Sales\Api\Data\OrderInterface $order)
    {
        $message = $proceed($payment, $amount, $order);

        if ($payment->getMethod() == "stripe_payments")
        {
            // Fixes https://github.com/magento/magento2/issues/26158
            $state = \Magento\Sales\Model\Order::STATE_NEW;
            $status = $order->getConfig()->getStateDefaultStatus($state);
            $order->setState($state)->setStatus($status);
        }

        return $message;
    }
}
