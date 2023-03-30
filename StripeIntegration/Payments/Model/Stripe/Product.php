<?php

namespace StripeIntegration\Payments\Model\Stripe;

class Product extends StripeObject
{
    protected $objectSpace = 'products';

    public function fromOrderItem($orderItem)
    {
        $data = [
            'name' => $orderItem->getName()
        ];

        $this->upsert($orderItem->getProductId(), $data);

        if (!$this->object)
            throw new \Magento\Framework\Exception\LocalizedException(__("The product \"%1\" could not be created in Stripe: %2", $orderItem->getName(), $this->lastError));

        return $this;
    }

    public function fromQuoteItem($quoteItem)
    {
        return $this->fromOrderItem($quoteItem);
    }
}
