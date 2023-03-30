<?php

namespace StripeIntegration\Payments\Model;

use StripeIntegration\Payments\Helper\Logger;
use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;

class SubscriptionProduct
{
    public $quoteItem = null;
    public $orderItem = null;
    public $product = null;

    public function __construct(
        \Magento\ConfigurableProduct\Api\LinkManagementInterface $linkManagement,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Helper\Generic $helper
    )
    {
        $this->linkManagement = $linkManagement;
        $this->config = $config;
        $this->helper = $helper;
    }

    public function fromQuoteItem($item)
    {
        if (empty($item) || !$item->getId())
            throw new InvalidSubscriptionProduct("Invalid quote item.");

        $this->quoteItem = $item;
        $this->product = null;

        return $this;
    }

    public function fromOrderItem($orderItem)
    {
        if (empty($orderItem) || !$orderItem->getId())
            throw new InvalidSubscriptionProduct("Invalid order item.");

        $this->orderItem = $orderItem;
        $this->product = $this->helper->loadProductById($orderItem->getProductId());

        return $this;
    }

    public function getProduct()
    {
        if ($this->product) // This will always be set if it was initialized from an order item
            return $this->product;

        if (!$this->quoteItem)
            return null;

        if (!$this->quoteItem->getProduct())
            return null;

        if (!$this->quoteItem->getProduct()->getId())
            return null;

        $productId = $this->quoteItem->getProduct()->getId();
        $product = $this->helper->loadProductById($productId);
        if (!$product || !$product->getId())
            return null;

        if ($product->getStripeSubEnabled() != 1)
            return null;

        return $this->product = $product;
    }

    public function getTrialDays()
    {
        $product = $this->getProduct();

        if (!$product)
            return null;

        if (empty($product->getStripeSubTrial()))
            return null;

        return $product->getStripeSubTrial();
    }

    public function hasTrialPeriod()
    {
        $trialDays = $this->getTrialDays();
        if (!is_numeric($trialDays) || $trialDays < 1)
            return false;

        return true;
    }

    public function getTrialEnd()
    {
        if (!$this->hasTrialPeriod())
            return null;

        $trialDays = $this->getTrialDays();
        $timeDifference = $this->helper->getStripeApiTimeDifference();

        return (time() + $trialDays * 24 * 60 * 60 + $timeDifference);
    }

    public function canUpgradeDowngrade()
    {
        if (!$this->isSubscriptionProduct())
            return false;

        if ($this->isConfigurableSubscription() &&
            $this->areUpgradesAllowed() &&
            $this->hasMoreSubscriptions()
            )
        {
            return true;
        }

        return false;
    }

    public function canChangeShipping()
    {
        if (!$this->isSubscriptionProduct())
            return false;

        if ($this->orderItem && $this->orderItem->getProductType() == "simple")
        {
            return true;
        }

        return false;
    }

    public function isSubscriptionProduct(
        ?\Magento\Catalog\Api\Data\ProductInterface $product = null
    )
    {
        if (!$product)
            $product = $this->product;

        if (!$product || !$product->getId())
            return false;

        $product = $this->product;

        if (!$product->getStripeSubEnabled())
            return false;

        $interval = $product->getStripeSubInterval();
        $intervalCount = $product->getStripeSubIntervalCount();

        if (!$interval)
            return false;

        if (!$intervalCount || !is_numeric($intervalCount))
            return false;

        if ($intervalCount <= 0)
            return false;

        return true;
    }

    // This method assumes that the orderItem product is an active subscription product,
    // i.e. it only checks the parent product.
    public function isConfigurableSubscription()
    {
        $orderItem = $this->orderItem;

        if (!$orderItem || !$orderItem->getId())
        {
            return false;
        }

        if (!in_array($orderItem->getProductType(), ['simple', 'virtual']))
        {
            return false;
        }

        if (!$orderItem->getParentItem() || !$orderItem->getParentItem()->getId())
        {
            return false;
        }

        $parentItem = $orderItem->getParentItem();

        if ($parentItem->getProductType() != "configurable")
        {
            return false;
        }

        return true;
    }

    public function areUpgradesAllowed()
    {
        $configurableProduct = $this->getParentConfigurableProduct();
        if (!$configurableProduct)
            return false;

        $selection = (int)$configurableProduct->getStripeSubUd();

        switch ($selection)
        {
            case 0:
                // Use config settings
                return (bool)$this->config->getConfigData("upgrade_downgrade", "subscriptions");
            case 1:
                // Disabled
                return false;
            case 2:
                // Enabled
                return true;
            default:
                return false;
        }
    }

    public function useProrationsForUpgrades()
    {
        if (!$this->areUpgradesAllowed())
            return false;

        $configurableProduct = $this->getParentConfigurableProduct();
        if (!$configurableProduct)
            return false;

        $selection = (int)$configurableProduct->getStripeSubProrateU();
        switch ($selection)
        {
            case 0:
                // Use config settings
                return (bool)$this->config->getConfigData("prorations_upgrades", "subscriptions");
            case 1:
                // Disabled
                return false;
            case 2:
                // Enabled
                return true;
            default:
                return false;
        }
    }

    public function useProrationsForDowngrades()
    {
        if (!$this->areUpgradesAllowed())
            return false;

        $configurableProduct = $this->getParentConfigurableProduct();
        if (!$configurableProduct)
            return false;

        $selection = (int)$configurableProduct->getStripeSubProrateD();
        switch ($selection)
        {
            case 0:
                // Use config settings
                return (bool)$this->config->getConfigData("prorations_downgrades", "subscriptions");
            case 1:
                // Disabled
                return false;
            case 2:
                // Enabled
                return true;
            default:
                return false;
        }
    }

    public function getParentConfigurableProduct()
    {
        $orderItem = $this->orderItem;
        if (!$orderItem || !$orderItem->getParentItem() || !$orderItem->getParentItem()->getProductId())
            return null;

        $configurableProductId = $orderItem->getParentItem()->getProductId();
        $configurableProduct = $this->helper->loadProductById($configurableProductId);
        if (!$configurableProduct || !$configurableProduct->getId())
            return null;

        return $configurableProduct;
    }

    public function hasMoreSubscriptions(): bool
    {
        $orderItem = $this->orderItem;
        if (!$orderItem || !$orderItem->getParentItem() || !$orderItem->getParentItem()->getProductId())
            return false;

        $configurableProductId = $orderItem->getParentItem()->getProductId();
        $currentSubscriptionProductId = $orderItem->getProductId();

        // Check if we have more than 1 subscription in the product configurations
        $subscriptionProducts = $this->getConfigurableProductSubscriptions($configurableProductId);

        if (count($subscriptionProducts) == 1)
        {
            $subscriptionProduct = array_pop($subscriptionProducts);
            if ($subscriptionProduct->getId() != $currentSubscriptionProductId)
            {
                return true;
            }
            else
            {
                return false;
            }
        }
        else if (count($subscriptionProducts) < 1)
        {
            return false;
        }
        else
        {
            return true;
        }
    }

    public function getConfigurableProductSubscriptions(int $productId): array
    {
        $subscriptionProducts = [];
        $configurableProduct = $this->helper->loadProductById($productId);
        if ($configurableProduct->getTypeId() != "configurable")
            return $subscriptionProducts;

        $childProducts = $this->linkManagement->getChildren($configurableProduct->getSku());
        foreach ($childProducts as $childProduct)
        {
            // Fully load the product
            $product = $this->helper->loadProductById($childProduct->getId());

            if ($this->isSubscriptionProduct($product))
            {
                $subscriptionProducts[$product->getId()] = $product;
            }
        }

        return $subscriptionProducts;
    }
}
