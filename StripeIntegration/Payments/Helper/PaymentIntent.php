<?php

namespace StripeIntegration\Payments\Helper;

use Magento\Framework\Exception\LocalizedException;

class PaymentIntent
{
    const ONLINE_ACTIONS = [
        'three_d_secure_redirect',
        'use_stripe_sdk'
    ];

    const CANCELABLE_STATUSES = [
        'requires_payment_method',
        'requires_capture',
        'requires_confirmation',
        'requires_action',
        'processing'
    ];

    public function isSuccessful($paymentIntent)
    {
        if (in_array($paymentIntent->status, ['succeeded', 'requires_capture', 'processing']))
        {
            return true;
        }

        return false;
    }

    public function requiresOfflineAction($paymentIntent)
    {
        if ($paymentIntent->status == "requires_action"
            && !empty($paymentIntent->next_action->type)
            && !in_array($paymentIntent->next_action->type, self::ONLINE_ACTIONS)
        )
        {
            return true;
        }

        return false;
    }

    public function canCancel($paymentIntent)
    {
        return in_array($paymentIntent->status, self::CANCELABLE_STATUSES)
            && empty($paymentIntent->invoice); // Subscription PIs cannot be canceled
    }

    public function canConfirm($paymentIntent)
    {
        return $paymentIntent->status == "requires_confirmation";
    }
}
