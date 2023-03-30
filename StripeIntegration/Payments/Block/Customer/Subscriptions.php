<?php

namespace StripeIntegration\Payments\Block\Customer;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use Magento\Framework\View\Element;
use StripeIntegration\Payments\Helper\Logger;

class Subscriptions extends \Magento\Framework\View\Element\Template
{
    public $customerPaymentMethods = null;
    public $helper;
    public $subscriptionsHelper;
    public $subscriptionBlocks = [];
    public $orders = [];
    public $configurableProductIds = [];
    public $subscriptionModels = [];
    public $subscriptionProductModels = [];

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Catalog\Api\ProductAttributeRepositoryInterface $attributeRepository,
        \Magento\Swatches\ViewModel\Product\Renderer\ConfigurableFactory $configurableViewModelFactory,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $subscriptionFactory,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Helper\Generic $helper,
        \StripeIntegration\Payments\Helper\PaymentMethod $paymentMethodHelper,
        \StripeIntegration\Payments\Helper\Data $dataHelper,
        \StripeIntegration\Payments\Helper\Subscriptions $subscriptionsHelper,
        array $data = []
    ) {
        $this->attributeRepository = $attributeRepository;
        $this->configurableViewModelFactory = $configurableViewModelFactory;
        $this->config = $config;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->stripeCustomer = $helper->getCustomerModel();
        $this->helper = $helper;
        $this->paymentMethodHelper = $paymentMethodHelper;
        $this->dataHelper = $dataHelper;
        $this->subscriptionsHelper = $subscriptionsHelper;

        parent::__construct($context, $data);
    }

    public function getSubscriptions()
    {
        try
        {
            $subscriptions = $this->stripeCustomer->getSubscriptions();
            $products = [];

            foreach ($subscriptions as $subscription)
            {
                $subscriptionItems = $this->stripeCustomer->getSubscriptionItems($subscription->id);

                foreach ($subscriptionItems as $subscriptionItem)
                {
                    if (!empty($subscriptionItem->price->product))
                        $products[$subscriptionItem->price->product->id] = $subscriptionItem->price->product;
                }
            }

            foreach ($subscriptions as &$subscription)
            {
                foreach ($subscription->items->data as $item)
                {
                    if (!empty($item->price->product) && is_string($item->price->product) && !empty($products[$item->price->product]))
                        $item->price->product = $products[$item->price->product];
                }
            }

            return $subscriptions;
        }
        catch (\Exception $e)
        {
            $this->helper->addError($e->getMessage());
            $this->helper->logError($e->getMessage());
            $this->helper->logError($e->getTraceAsString());
        }
    }

    public function getSubscriptionDefaultPaymentMethod($sub)
    {
        if (!empty($sub->default_payment_method))
        {
            $methods = [
                $sub->default_payment_method->type => [
                    $sub->default_payment_method
                ]
            ];
            $formattedMethods = $this->paymentMethodHelper->formatPaymentMethods($methods);
            return array_pop($formattedMethods);
        }

        return null;
    }

    public function getSubscriptionPaymentMethodId($sub)
    {
        $method = $this->getSubscriptionDefaultPaymentMethod($sub);

        if ($method)
            return $method['id'];
        else
            return null;
    }

    public function getInvoiceItems($sub)
    {
        $items = [];

        if (empty($sub->items->data))
            return $items;

        foreach ($sub->items->data as $item)
        {
            if ($item->quantity > 1)
                $qty = $item->quantity . " x ";
            else
                $qty = "";

            if (!empty($item->price->product->name))
                $items[] = $qty . $item->price->product->name;
        }

        return $items;
    }

    public function formatSubscriptionName($sub)
    {
        return $this->subscriptionsHelper->formatSubscriptionName($sub);
    }

    public function getCustomerPaymentMethods()
    {
        if (isset($this->customerPaymentMethods))
            return $this->customerPaymentMethods;

        return $this->customerPaymentMethods = $this->stripeCustomer->getSavedPaymentMethods(\StripeIntegration\Payments\Helper\PaymentMethod::SUPPORTS_SUBSCRIPTIONS, true);
    }

    public function getStatus($sub)
    {
        switch ($sub->status)
        {
            case 'trialing': // Trialing is not supported yet
            case 'active':
                return __("Active");
            case 'past_due':
                return __("Past Due");
            case 'unpaid':
                return __("Unpaid");
            case 'canceled':
                return __("Canceled");
            default:
                return __(ucwords(explode('_', $sub->status)));
        }
    }

    protected function createConfigurableSubscriptionBlock(\Stripe\Subscription $subscription)
    {
        $product = $this->subscriptionsHelper->getConfigurableSubscriptionProductFrom($subscription);
        if (!$product || !$product->getId())
            return null;

        // DO NOT use the text swatch, it caches the config values as if this is a single product page
        // The UI Component needs to be re-written to support multiple products per page
        $attribute = false; //$this->subscriptionsHelper->getConfigurableSubscriptionSuperAttribute($subscription);
        if ($attribute && $attribute->getProductAttribute() && $attribute->getProductAttribute()->getSwatchInputType() == "text")
        {
            $block = $this->getLayout()->createBlock('Magento\Swatches\Block\Product\Renderer\Configurable');
            $block->setTemplate('Magento_Swatches::product/view/renderer.phtml');

            $model = $this->configurableViewModelFactory->create();
            $block->setConfigurableViewModel($model);
        }
        else
        {
            $block = $this->getLayout()->createBlock('StripeIntegration\Payments\Block\Customer\ConfigurableSubscription');
            $block->setTemplate('StripeIntegration_Payments::product/view/type/options/configurable_dropdown.phtml');
            $block->setSubscription($subscription);
        }
        $block->setBlockId('update_subscription_' . $subscription->id);
        $block->setProduct($product);

        $this->subscriptionBlocks[$subscription->id] = $block;

        return $block;
    }

    protected function getConfigurableSubscriptionBlock(\Stripe\Subscription $subscription)
    {
        if (isset($this->subscriptionBlocks[$subscription->id]))
            return $this->subscriptionBlocks[$subscription->id];

        $this->subscriptionBlocks[$subscription->id] = $this->createConfigurableSubscriptionBlock($subscription);

        return $this->subscriptionBlocks[$subscription->id];
    }

    public function getSubscriptionUpdateOptions(\Stripe\Subscription $subscription)
    {
        $block = $this->getConfigurableSubscriptionBlock($subscription);

        if ($block)
            return $block->toHtml();
        else
            return '';
    }

    public function getConfigurableSubscriptionQty(\Stripe\Subscription $subscription): int
    {
        return $this->subscriptionsHelper->getConfigurableSubscriptionQty($subscription);
    }

    public function getSubscriptionModel(\Stripe\Subscription $subscription): ?\StripeIntegration\Payments\Model\Stripe\Subscription
    {
        if (isset($this->subscriptionModels[$subscription->id]))
            return $this->subscriptionModels[$subscription->id];

        try
        {
            $subscriptionModel = $this->subscriptionFactory->create()->fromSubscription($subscription);
            $this->subscriptionModels[$subscription->id] = $subscriptionModel;
        }
        catch (\Exception $e)
        {
            $this->helper->logError("Could not load subscription model for subscription {$subscription->id}: " . $e->getMessage());
            $this->subscriptionModels[$subscription->id] = null;
        }

        return $this->subscriptionModels[$subscription->id];
    }
}
