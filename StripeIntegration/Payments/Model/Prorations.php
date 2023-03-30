<?php
namespace StripeIntegration\Payments\Model;

use Magento\Quote\Model\Quote\Address\Total\AbstractTotal;
use Magento\Quote\Model\Quote;
use Magento\Quote\Api\Data\ShippingAssignmentInterface;
use Magento\Quote\Model\Quote\Address\Total;

class Prorations extends AbstractTotal
{
    public function __construct(
        \Magento\Store\Model\StoreManagerInterface $storeManager
    )
    {
        $this->setCode('prorations');
        $this->storeManager = $storeManager;
    }

    /**
     * @param Quote $quote
     * @param ShippingAssignmentInterface $shippingAssignment
     * @param Total $total
     * @return $this
     */
    public function collect(
        Quote $quote,
        ShippingAssignmentInterface $shippingAssignment,
        Total $total
    ) {
        parent::collect($quote, $shippingAssignment, $total);

        $items = $shippingAssignment->getItems();
        if (!count($items))
            return $this;

        $amount = 0;
        $baseAmount = 0;

        $total->setProrationsAmount($amount);
        $total->setBaseProrationsAmount($baseAmount);

        // Add the fee to the grand total
        $total->addTotalAmount('prorations', $amount);
        $total->addBaseTotalAmount('base_prorations', $baseAmount);

        return $this;
    }

    /**
     * @param Total $total
     */
    protected function clearValues(Total $total)
    {
        $total->setTotalAmount('prorations', 0);
        $total->setBaseTotalAmount('base_prorations', 0);
        $total->setProrationsAmount(0);
        $total->setBaseProrationsAmount(0);
        $total->setGrandTotal(0);
        $total->setBaseGrandTotal(0);
    }

    /**
     * @param Quote $quote
     * @param Total $total
     * @return array
     */
    public function fetch(Quote $quote, Total $total)
    {
        $amount = 0;
        $baseAmount = 0;

        // $store = $this->storeManager->getStore();
        // $amount = $store->getBaseCurrency()->convert($baseAmount, $store->getCurrentCurrencyCode());

        if ($baseAmount)
        {
            return [
                'code' => $this->getCode(),
                'title' => 'Prorations',
                'base_value' => $baseAmount,
                'value' => $amount
            ];
        }

        return null;
    }

    /**
     * @return \Magento\Framework\Phrase
     */
    public function getLabel()
    {
        return __('Prorations');
    }
}
