<?php

namespace StripeIntegration\Payments\Block\Customer;

class ConfigurableSubscription extends \Magento\ConfigurableProduct\Block\Product\View\Type\Configurable
{
    protected $subscription = null;
    protected $order = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \Magento\ConfigurableProduct\Model\Product\Type\ConfigurableFactory $configurableProductFactory,
        \Magento\Catalog\Block\Product\Context $context,
        \Magento\Framework\Stdlib\ArrayUtils $arrayUtils,
        \Magento\Framework\Json\EncoderInterface $jsonEncoder,
        \Magento\ConfigurableProduct\Helper\Data $helper,
        \Magento\Catalog\Helper\Product $catalogProduct,
        \Magento\Customer\Helper\Session\CurrentCustomer $currentCustomer,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\ConfigurableProduct\Model\ConfigurableAttributeData $configurableAttributeData,
        array $data = [],
        \Magento\Framework\Locale\Format $localeFormat = null,
        \Magento\Customer\Model\Session $customerSession = null,
        \Magento\ConfigurableProduct\Model\Product\Type\Configurable\Variations\Prices $variationPrices = null
    ) {
        $this->paymentsHelper = $paymentsHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;
        $this->dataHelper = $dataHelper;
        $this->config = $config;
        $this->configurableProductFactory = $configurableProductFactory;

        parent::__construct(
            $context,
            $arrayUtils,
            $jsonEncoder,
            $helper,
            $catalogProduct,
            $currentCustomer,
            $priceCurrency,
            $configurableAttributeData,
            $data,
            $localeFormat,
            $customerSession,
            $variationPrices
        );
    }

    public function setSubscription(\Stripe\Subscription $subscription)
    {
        $this->subscription = $subscription;
        $this->loadOrderFromSubscription($subscription);
    }

    protected function loadOrderFromSubscription(\Stripe\Subscription $subscription)
    {
        $orderIncrementId = $this->subscriptionsHelper->getSubscriptionOrderID($subscription);
        if (!$orderIncrementId)
            return;

        $this->order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
    }

    protected function isSelected($configurableProduct, $attributeId, $option)
    {
        if (!$this->order)
            return false;

        foreach ($this->order->getAllVisibleItems() as $orderItem)
        {
            if ($orderItem->getProductId() == $configurableProduct->getId())
            {
                $buyRequest = $this->dataHelper->getConfigurableProductBuyRequest($orderItem);

                if (empty($buyRequest['super_attribute'][$attributeId]))
                    continue;

                if ($buyRequest['super_attribute'][$attributeId] == $option['value_index'])
                    return true;
            }
        }

        return false;
    }

    public function getProductFrom($configurableProduct, int $attributeId, int $selectedOptionId)
    {
        $superAttribute = [$attributeId => $selectedOptionId];

        if ($configurableProduct->getTypeId() == 'configurable')
        {
            $attributes = $superAttribute;
            $configurableProduct2 = $this->configurableProductFactory->create();
            $product = $configurableProduct2->getProductByAttributes($attributes, $configurableProduct);
            if (!$product || !$product->getId())
                return null;

            return $this->paymentsHelper->loadProductById($product->getId());
        }

        return null;
    }

    public function getUpdateOptions($attribute): array
    {
        $options = [];

        $configurableProductId = $attribute->getProductId();
        $attributeId = $attribute->getAttributeId();
        $configurableProduct = $this->getProduct();

        foreach ($attribute->getOptions() as $option)
        {
            $product = $this->getProductFrom($configurableProduct, $attributeId, $option['value_index']);
            if ($this->subscriptionsHelper->isSubscriptionProduct($product) && $product->getIsSalable())
            {
                $option['is_selected'] = $this->isSelected($configurableProduct, $attributeId, $option);
                $options[] = $option;
            }
        }

        return $options;
    }
}
