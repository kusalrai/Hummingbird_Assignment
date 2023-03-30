<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;
use StripeIntegration\Payments\Model\Config;
use Psr\Log\LoggerInterface;
use Magento\Framework\Validator\Exception;
use StripeIntegration\Payments\Helper\Logger;

class Order
{
    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\PaymentIntent $paymentIntentModel
    )
    {
        $this->paymentsHelper = $paymentsHelper;
        $this->config = $config;
        $this->paymentIntentModel = $paymentIntentModel;
    }

    public function onMultishippingChargeSucceeded($order, $object)
    {
        // DO NOT call saveOrder() in here. A 3DS may still be happening which will record transactions and save the order elsewhere
        $this->paymentsHelper->sendNewOrderEmailFor($order);
    }

    public function onTransaction($order, $object, $transactionId)
    {
        $action = __("Collected");
        if ($object["captured"] == false)
        {
            $action = __("Authorized");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_AUTH;
            $transactionAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount'], $object['currency'], $order);
        }
        else
        {
            $action = __("Captured");
            $transactionType = \Magento\Sales\Model\Order\Payment\Transaction::TYPE_CAPTURE;
            $transactionAmount = $this->paymentsHelper->convertStripeAmountToOrderAmount($object['amount_captured'], $object['currency'], $order);
        }

        $transaction = $order->getPayment()->addTransaction($transactionType, null, false);
        $transaction->setAdditionalInformation("amount", $transactionAmount);
        $transaction->setAdditionalInformation("currency", $object['currency']);
        $transaction->save();

        $state = \Magento\Sales\Model\Order::STATE_PROCESSING;
        $status = $order->getConfig()->getStateDefaultStatus($state);
        $humanReadableAmount = $this->paymentsHelper->addCurrencySymbol($transactionAmount, $object['currency']);
        $comment = __("%1 amount of %2 via Stripe. Transaction ID: %3", $action, $humanReadableAmount, $transactionId);
        $order->setState($state)->addStatusToHistory($status, $comment, $isCustomerNotified = false);
    }
}
