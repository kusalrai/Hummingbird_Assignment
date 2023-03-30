<?php

namespace StripeIntegration\Payments\Helper;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use StripeIntegration\Payments\Exception\SCANeededException;
use StripeIntegration\Payments\Exception\CacheInvalidationException;
use StripeIntegration\Payments\Exception\InvalidSubscriptionProduct;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\CouldNotSaveException;


class Subscriptions
{
    public $couponCodes = [];
    public $coupons = [];
    public $subscriptions = [];
    public $invoices = [];
    public $paymentIntents = [];
    public $trialingSubscriptionsAmounts = null;
    public $shippingTaxPercent = null;

    public function __construct(
        \StripeIntegration\Payments\Helper\Generic $paymentsHelper,
        \StripeIntegration\Payments\Helper\Compare $compare,
        \StripeIntegration\Payments\Helper\Address $addressHelper,
        \StripeIntegration\Payments\Model\Config $config,
        \StripeIntegration\Payments\Model\SubscriptionProductFactory $subscriptionProductFactory,
        \StripeIntegration\Payments\Model\Stripe\SubscriptionFactory $stripeSubscriptionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Subscription\CollectionFactory $subscriptionCollectionFactory,
        \StripeIntegration\Payments\Model\ResourceModel\Coupon\Collection $couponCollection,
        \Magento\ConfigurableProduct\Api\LinkManagementInterface $linkManagement,
        \Magento\Framework\Event\ManagerInterface $eventManager,
        \Magento\Framework\Pricing\PriceCurrencyInterface $priceCurrency,
        \Magento\Framework\App\CacheInterface $cache,
        \Magento\Tax\Model\Sales\Order\TaxManagement $taxManagement,
        \Magento\Sales\Model\Service\InvoiceService $invoiceService,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Sales\Model\Service\CreditmemoService $creditmemoService,
        \Magento\Sales\Model\Order\Creditmemo\ItemFactory $creditmemoItemFactory,
        \StripeIntegration\Payments\Model\SubscriptionFactory $subscriptionFactory,
        \Magento\SalesRule\Model\CouponFactory $couponFactory,
        \StripeIntegration\Payments\Model\CouponFactory $stripeCouponFactory,
        \StripeIntegration\Payments\Helper\TaxHelper $taxHelper,
        \StripeIntegration\Payments\Helper\RecurringOrderFactory $recurringOrderFactory
    ) {
        $this->paymentsHelper = $paymentsHelper;
        $this->compare = $compare;
        $this->addressHelper = $addressHelper;
        $this->config = $config;
        $this->subscriptionProductFactory = $subscriptionProductFactory;
        $this->stripeSubscriptionFactory = $stripeSubscriptionFactory;
        $this->subscriptionCollectionFactory = $subscriptionCollectionFactory;
        $this->couponCollection = $couponCollection;
        $this->linkManagement = $linkManagement;
        $this->priceCurrency = $priceCurrency;
        $this->eventManager = $eventManager;
        $this->customer = $paymentsHelper->getCustomerModel();
        $this->cache = $cache;
        $this->taxManagement = $taxManagement;
        $this->invoiceService = $invoiceService;
        $this->quoteRepository = $quoteRepository;
        $this->creditmemoFactory = $creditmemoFactory;
        $this->creditmemoService = $creditmemoService;
        $this->creditmemoItemFactory = $creditmemoItemFactory;
        $this->subscriptionFactory = $subscriptionFactory;
        $this->couponFactory = $couponFactory;
        $this->stripeCouponFactory = $stripeCouponFactory;
        $this->taxHelper = $taxHelper;
        $this->recurringOrderFactory = $recurringOrderFactory;
    }

    public function getSubscriptionExpandParams()
    {
        return ['latest_invoice.payment_intent', 'pending_setup_intent'];
    }

    public function getSubscriptionParamsFromQuote($quote, $paymentIntentParams, $order = null)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return null;

        $subscriptions = $this->getSubscriptionsFromQuote($quote);
        $subscriptionItems = $this->getSubscriptionItemsFromQuote($quote, $subscriptions, $order);

        if (empty($subscriptionItems))
            return null;

        $stripeCustomer = $this->customer->createStripeCustomerIfNotExists();
        $this->customer->save();

        if (!$stripeCustomer)
            throw new \Exception("Could not create customer in Stripe.");

        $metadata = $subscriptionItems[0]['metadata']; // There is only one item for the entire order

        $params = [
            'customer' => $stripeCustomer->id,
            'items' => $subscriptionItems,
            'payment_behavior' => 'default_incomplete',
            'expand' => $this->getSubscriptionExpandParams(),
            'metadata' => $metadata,
            'payment_settings' => [
                'save_default_payment_method' => 'on_subscription'
            ]
        ];

        $couponId = $this->getCouponId($subscriptions);
        if ($couponId)
            $params['coupon'] = $couponId;

        if ($paymentIntentParams['amount'] > 0)
        {
            $stripeDiscountAdjustment = $this->getStripeDiscountAdjustment($subscriptions);
            $normalPrice = $this->createPriceForOneTimePayment($quote, $paymentIntentParams, $stripeDiscountAdjustment);
            $params['add_invoice_items'] = [[
                "price" => $normalPrice->id,
                "quantity" => 1
            ]];
        }

        foreach ($quote->getAllItems() as $quoteItem)
        {
            try
            {
                $product = $this->subscriptionProductFactory->create()->fromQuoteItem($quoteItem);
                if ($product->hasTrialPeriod())
                {
                    $params["trial_end"] = $product->getTrialEnd();
                    break;
                }
            }
            catch (InvalidSubscriptionProduct $e)
            {
                // Ignore non subscription products
            }

        }

        // Overwrite trial end if we are migrating the subscription from the CLI
        foreach ($subscriptions as $subscription)
        {
            if ($subscription['profile']['trial_end'])
                $params['trial_end'] = $subscription['profile']['trial_end'];
        }

        return $params;
    }

    public function filterToUpdateableParams($params)
    {
        $updateParams = [];

        if (empty($params))
            return $updateParams;

        $updateable = ['metadata', 'trial_end', 'expand'];

        foreach ($params as $key => $value)
        {
            if (in_array($key, $updateable))
                $updateParams[$key] = $value;
        }

        return $updateParams;
    }

    public function invalidateSubscription($subscription, $params)
    {
        $subscriptionItems = [];

        foreach ($params["items"] as $item)
        {
            $subscriptionItems[] = [
                "metadata" => [
                    "Type" => $item["metadata"]["Type"],
                    "SubscriptionProductIDs" => $item["metadata"]["SubscriptionProductIDs"]
                ],
                "price" => [
                    "id" => $item["price"]
                ],
                "quantity" => $item["quantity"]
            ];
        }

        $expectedValues = [
            "customer" => $params["customer"],
            "items" => [
                "data" => $subscriptionItems
            ]
        ];

        if (!empty($params['add_invoice_items']))
        {
            $oneTimeAmount = "unset";
            foreach ($params['add_invoice_items'] as $item)
            {
                $oneTimeAmount = [
                    "price" => [
                        "id" => $item["price"]
                    ],
                    "quantity" => $item["quantity"]
                ];
            }

            if (empty($subscription->latest_invoice->lines->data))
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");

            $hasRegularItems = false;
            foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
            {
                if (!empty($invoiceLineItem->price->recurring->interval))
                    continue; // This is a subscription item

                $hasRegularItems = true;

                if ($this->compare->isDifferent($invoiceLineItem, $oneTimeAmount))
                {
                    throw new CacheInvalidationException("Non-updateable subscription details have changed: One time payment amount has changed.");
                }
            }

            if (!$hasRegularItems && $oneTimeAmount !== "unset")
                throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were added to the cart.");
        }
        else
        {
            if (!empty($subscription->latest_invoice->lines->data))
            {
                foreach ($subscription->latest_invoice->lines->data as $invoiceLineItem)
                {
                    if (empty($invoiceLineItem->price->recurring->interval))
                        throw new CacheInvalidationException("Non-updateable subscription details have changed: Regular items were removed from the cart.");
                }
            }
        }

        if (!empty($params['coupon']))
        {
            $expectedValues['latest_invoice']['discount']['coupon']['id'] = $params['coupon'];
        }
        else
        {
            $expectedValues['latest_invoice']['discount'] = "unset";
        }

        if ($this->compare->isDifferent($subscription, $expectedValues))
            throw new CacheInvalidationException("Non-updateable subscription details have changed: " . $this->compare->lastReason);
    }

    public function updateSubscriptionFromQuote($quote, $subscriptionId, $paymentIntentParams)
    {
        $params = $this->getSubscriptionParamsFromQuote($quote, $paymentIntentParams);

        if (empty($params))
            return null; // The cart may not include subscriptions

        if (!$subscriptionId)
            return $this->config->getStripeClient()->subscriptions->create($params);

        try
        {
            $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, [
                'expand' => $this->getSubscriptionExpandParams()
            ]);
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError("Could not retrieve subscription $subscriptionId: " . $e->getMessage());

            return $this->config->getStripeClient()->subscriptions->create($params);
        }

        try
        {
            $this->invalidateSubscription($subscription, $params);
        }
        catch (CacheInvalidationException $e)
        {
            $this->paymentsHelper->logError("Will re-create subscription: " . $e->getMessage());

            try
            {
                $this->config->getStripeClient()->subscriptions->cancel($subscriptionId);
            }
            catch (\Exception $e)
            {

            }

            return $this->config->getStripeClient()->subscriptions->create($params);
        }

        $updateParams = $this->filterToUpdateableParams($params);

        if (empty($updateParams))
            return $subscription;

        if ($this->compare->isDifferent($subscription, $updateParams))
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

        return $subscription;
    }

    public function updateSubscriptionFromOrder($order, $subscriptionId, $paymentIntentParams)
    {
        $quote = $this->paymentsHelper->loadQuoteById($order->getQuoteId());

        if (empty($quote) || !$quote->getId())
            throw new \Exception("The quote for this order could not be loaded");

        $params = $this->getSubscriptionParamsFromQuote($quote, $paymentIntentParams, $order);

        if (empty($params))
            return null;

        if (!$subscriptionId)
        {
            $subscription = $this->config->getStripeClient()->subscriptions->create($params);
            $this->updateSubscriptionEntry($subscription, $order);
            return $subscription;
        }

        $subscription = $this->config->getStripeClient()->subscriptions->retrieve($subscriptionId, [
            'expand' => $this->getSubscriptionExpandParams()
        ]);

        try
        {
            if (!$order->getPayment()->getAdditionalInformation('is_migrated_subscription'))
                $this->invalidateSubscription($subscription, $params);
        }
        catch (CacheInvalidationException $e)
        {
            $this->paymentsHelper->logError($e->getMessage());
            throw new LocalizedException(__("The cart details have changed. Please refresh the page and try again."));
        }

        $updateParams = $this->filterToUpdateableParams($params);

        if (empty($updateParams))
        {
            $this->updateSubscriptionEntry($subscription, $order);
            return $subscription;
        }

        if ($this->compare->isDifferent($subscription, $updateParams))
            $subscription = $this->config->getStripeClient()->subscriptions->update($subscriptionId, $updateParams);

        if (!empty($subscription->latest_invoice->payment_intent->id))
        {
            $params = [];
            $params["description"] = $this->paymentsHelper->getOrderDescription($order);
            $params["metadata"] = $this->config->getMetadata($order);
            $shipping = $this->addressHelper->getShippingAddressFromOrder($order);
            if ($shipping)
                $params['shipping'] = $shipping;

            $paymentIntent = $this->config->getStripeClient()->paymentIntents->update($subscription->latest_invoice->payment_intent->id, $params);
            $subscription->latest_invoice->payment_intent = $paymentIntent;
        }

        $this->updateSubscriptionEntry($subscription, $order);

        return $subscription;
    }

    public function getSubscriptionItemsFromQuote($quote, $subscriptions, $order = null)
    {
        if (empty($subscriptions))
            return null;

        if (!$this->renewTogether($subscriptions))
            throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));

        $recurringPrice = $this->createSubscriptionPriceForSubscriptions($quote, $subscriptions);

        $items = [];
        $metadata = $this->collectMetadataForSubscriptions($quote, $subscriptions, $order);

        $items[] = [
            "metadata" => $metadata,
            "price" => $recurringPrice->id,
            "quantity" => 1
        ];

        return $items;
    }

    /**
     * Returns array [
     *   [
     *     \Magento\Catalog\Model\Product,
     *     \Magento\Sales\Model\Quote\Item,
     *     array $profile
     *   ],
     *   ...
     * ]
     */
    public function getSubscriptionsFromQuote($quote)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return [];

        $items = $quote->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromQuoteItem($item);
            if (!$product)
                continue;

            $subscriptions[] = [
                'product' => $product,
                'quote_item' => $item,
                'profile' => $this->getSubscriptionDetails($product, $quote, $item)
            ];
        }

        return $subscriptions;
    }

    /**
     * Returns array [
     *   [
     *     \Magento\Catalog\Model\Product,
     *     \Magento\Sales\Model\Order\Item,
     *     array $profile
     *   ],
     *   ...
     * ]
     */
    public function getSubscriptionsFromOrder($order)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return [];

        $items = $order->getAllItems();
        $subscriptions = [];

        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $subscriptions[$item->getQuoteItemId()] = [
                'product' => $product,
                'order_item' => $item,
                'profile' => $this->getSubscriptionDetails($product, $order, $item)
            ];
        }

        return $subscriptions;
    }

    public function getSubscriptionIntervalKeyFromProduct($product)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return null;

        if (!$this->isSubscriptionProduct($product))
            return null;

        $key = '';
        $trialDays = $this->getTrialDays($product);
        if ($trialDays > 0)
            $key .= "trial_" . $trialDays . "_";

        $interval = $product->getStripeSubInterval();
        $intervalCount = $product->getStripeSubIntervalCount();

        if ($interval && $intervalCount && $intervalCount > 0)
            $key .= $interval . "_" . $intervalCount;

        return $key;
    }

    public function getQuote()
    {
        $quote = $this->paymentsHelper->getQuote();
        $createdAt = $quote->getCreatedAt();
        if (empty($createdAt)) // case of admin orders
        {
            $quoteId = $quote->getQuoteId();
            $quote = $this->paymentsHelper->loadQuoteById($quoteId);
        }
        return $quote;
    }

    public function isOrder($order)
    {
        if (!empty($order->getOrderCurrencyCode()))
            return true;

        return false;
    }

    public function getSubscriptionDetails($product, $order, $item)
    {
        // Get billing interval and billing period
        $interval = $product->getStripeSubInterval();
        $intervalCount = $product->getStripeSubIntervalCount();

        if (!$interval)
            throw new \Exception("An interval period has not been specified for the subscription");

        if (!$intervalCount)
            $intervalCount = 1;

        $name = $item->getName();
        $qty = max(/* quote */ $item->getQty(), /* order */ $item->getQtyOrdered());
        $originalItem = $item;
        $item = $this->paymentsHelper->getSubscriptionQuoteItemWithTotalsFrom($item, $order);

        // For subscription migrations via the CLI, we set the trial period manually
        if ($order->getPayment() && $order->getPayment()->getAdditionalInformation("subscription_start"))
        {
            $trialEnd = $order->getPayment()->getAdditionalInformation("subscription_start");
            if (!is_numeric($trialEnd) || $trialEnd < 0)
                $trialEnd = null;
        }
        else
            $trialEnd = null;

        // Get the subscription currency and amount
        $initialFee = $product->getStripeSubInitialFee();

        if (!is_numeric($initialFee))
            $initialFee = 0;

        if ($this->config->priceIncludesTax())
        {
            $baseAmount = $item->getBasePriceInclTax();
            $amount = $item->getPriceInclTax();
        }
        else
        {
            $baseAmount = $item->getBasePrice();
            $amount = $item->getPrice();
        }

        $discount = $item->getDiscountAmount();
        $tax = $item->getTaxAmount();

        if ($this->isOrder($order))
        {
            $currency = $order->getOrderCurrencyCode();
            $rate = $order->getBaseToOrderRate();
        }
        else
        {
            $currency = $order->getQuoteCurrencyCode();
            $rate = $order->getBaseToQuoteRate();
        }

        $baseDiscount = $item->getBaseDiscountAmount();
        $baseTax = $item->getBaseTaxAmount();
        $baseCurrency = $order->getBaseCurrencyCode();
        $baseShippingTaxAmount = 0;
        $baseShipping = 0;

        // This seems to be a Magento multi-currency bug, tested in v2.3.2
        if (is_numeric($rate) && $rate > 0 && $rate != 1 && $amount == $item->getBasePrice())
            $amount = round(floatval($amount * $rate), 2); // We fix it by doing the calculation ourselves

        if (is_numeric($rate) && $rate > 0)
            $initialFee = round(floatval($initialFee * $rate), 2);

        if ($this->isOrder($order))
        {
            $quote = $this->paymentsHelper->getQuoteFromOrder($order);
            $quoteItem = null;
            if (!$quote || !$quote->getId())
            {
                $quote = $this->createQuoteFromOrder($order);
            }

            foreach ($quote->getAllItems() as $qItem)
            {
                if ($qItem->getSku() == $item->getSku())
                {
                    $quoteItem = $qItem;

                    if ($quoteItem->getParentItemId() && $originalItem->getParentItem() && $originalItem->getParentItem()->getProductType() == "configurable")
                    {
                        $qty = $item->getQtyOrdered() * $quoteItem->getQty();
                        $quoteItem->setQtyCalculated($qty);
                    }
                }
            }

            if ($item->getShippingAmount())
            {
                $shipping = $item->getShippingAmount();
            }
            else if ($item->getBaseShippingAmount())
            {
                $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($item->getBaseShippingAmount());
            }
            else
            {
                $baseShipping = $this->taxHelper->getBaseShippingAmountForQuoteItem($quoteItem, $quote);
                $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($baseShipping);
            }

            $orderShippingAmount = $order->getShippingAmount();
            $orderShippingTaxAmount = $order->getShippingTaxAmount();
            $shippingTaxPercent = $this->taxHelper->getTaxPercentForOrder($order->getId(), "shipping");

            if ($orderShippingAmount == $shipping)
            {
                $shippingTaxAmount = $orderShippingTaxAmount;
            }
            else
            {
                $shippingTaxAmount = 0;

                if ($shippingTaxPercent && is_numeric($shippingTaxPercent) && $shippingTaxPercent > 0)
                {
                    if ($this->config->shippingIncludesTax())
                        $shippingTaxAmount = $this->taxHelper->taxInclusiveTaxCalculator($shipping, $shippingTaxPercent);
                    else
                        $shippingTaxAmount = $this->taxHelper->taxExclusiveTaxCalculator($shipping, $shippingTaxPercent);
                }
            }
        }
        else
        {
            $quote = $order;
            $quoteItem = $item;

            // Case for configurable and bundled subscriptions
            if ($quoteItem->getProductType() != $originalItem->getProductType())
            {
                $qty = $quoteItem->getQty();
                $name = $quoteItem->getName();
            }

            $baseShipping = $this->taxHelper->getBaseShippingAmountForQuoteItem($quoteItem, $quote);
            $shippingTaxRate = $this->taxHelper->getShippingTaxRateFromQuote($quote);
            $shipping = $this->paymentsHelper->convertBaseAmountToStoreAmount($baseShipping);

            $shippingTaxAmount = 0;
            $shippingTaxPercent = 0;

            if ($shipping > 0 && $shippingTaxRate)
            {
                $shippingTaxPercent = $shippingTaxRate["percent"];
                $shippingTaxAmount = $shippingTaxRate["amount"];
                $baseShippingTaxAmount = $shippingTaxRate["base_amount"];
            }
        }

        if (!is_numeric($amount))
            $baseAmount = $amount = 0;

        if ($order->getPayment()->getAdditionalInformation("remove_initial_fee"))
            $initialFee = 0;

        if ($this->config->priceIncludesTax())
            $initialFeeTaxAmount = $this->taxHelper->taxInclusiveTaxCalculator($initialFee * $qty, $item->getTaxPercent());
        else
            $initialFeeTaxAmount = $this->taxHelper->taxExclusiveTaxCalculator($initialFee * $qty, $item->getTaxPercent());

        $params = [
            'name' => $name,
            'qty' => $qty,
            'interval' => $interval,
            'interval_count' => $intervalCount,
            'base_amount_magento' => $baseAmount,
            'amount_magento' => $amount,
            'amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($amount, $currency),
            'initial_fee_stripe' => 0,
            'initial_fee_magento' => 0,
            'discount_amount_magento' => $discount,
            'base_discount_amount_magento' => $baseDiscount,
            'discount_amount_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($discount, $currency),
            'shipping_magento' => round(floatval($shipping), 2),
            'base_shipping_magento' => round(floatval($baseShipping), 2),
            'shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shipping, $currency),
            'currency' => strtolower($currency),
            'base_currency' => strtolower($baseCurrency),
            'tax_percent' => $item->getTaxPercent(),
            'tax_percent_shipping' => $shippingTaxPercent,
            'tax_amount_item' => $tax, // already takes $qty into account
            'base_tax_amount_item' => $baseTax, // already takes $qty into account
            'tax_amount_item_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($tax, $currency), // already takes $qty into account
            'tax_amount_shipping' => round($shippingTaxAmount, 4),
            'base_tax_amount_shipping' => round($baseShippingTaxAmount, 2),
            'tax_amount_shipping_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($shippingTaxAmount, $currency),
            'tax_amount_initial_fee' => round($initialFeeTaxAmount, 4),
            'tax_amount_initial_fee_stripe' => $this->paymentsHelper->convertMagentoAmountToStripeAmount($initialFeeTaxAmount, $currency),
            'trial_end' => $trialEnd,
            'trial_days' => 0,
            'expiring_coupon' => null,
            'expiring_tax_amount_item' => 0,
            'expiring_base_tax_amount_item' => 0,
            'expiring_discount_amount_magento' => 0,
            'expiring_base_discount_amount_magento' => 0,
            'product_id' => $product->getId()
        ];

        if (!$trialEnd)
        {
            // The following should not be used with subscriptions which are migrated via the CLI tool.
            $params['trial_days'] = $this->getTrialDays($product);

            if ($discount > 0)
            {
                $couponModel = $this->getExpiringCoupon($order);
                if ($couponModel)
                {
                    $params['expiring_coupon'] = $couponModel->getData();
                }
            }

            $params['initial_fee_stripe'] = $this->paymentsHelper->convertMagentoAmountToStripeAmount($initialFee, $currency);
            $params['initial_fee_magento'] = $initialFee;
        }

        if (!empty($params['expiring_coupon']))
        {
            // When the coupon expires, we want to increase the tax to the non-discounted amount, so we overwrite it here
            $taxAmountItem = round($params['amount_magento'] * $params['qty'] * ($params['tax_percent'] / 100), 4);
            $baseTaxAmountItem = round($params['base_amount_magento'] * $params['qty'] * ($params['tax_percent'] / 100), 4);
            $taxAmountItemStripe = $this->paymentsHelper->convertMagentoAmountToStripeAmount($taxAmountItem, $params['currency']);

            $diffTaxAmountItem = $taxAmountItem - $params['tax_amount_item'];
            $diffBaseTaxAmountItem = $baseTaxAmountItem - $params['base_tax_amount_item'];
            $diffTaxAmountItemStripe = $taxAmountItemStripe - $params['tax_amount_item_stripe'];

            // Increase the tax
            $params['tax_amount_item'] += $diffTaxAmountItem;
            $params['base_tax_amount_item'] += $diffBaseTaxAmountItem;
            $params['tax_amount_item_stripe'] += $diffTaxAmountItemStripe;

            // And also increase the discount to cover the tax of the non-discounted amount
            $params['discount_amount_magento'] += $diffTaxAmountItem;
            $params['base_discount_amount_magento'] += $diffBaseTaxAmountItem;
            $params['discount_amount_stripe'] += $diffTaxAmountItemStripe;

            // Set the expiring amount adjustments so that they offset the totals displayed at the front-end
            $params['expiring_tax_amount_item'] = $diffTaxAmountItem;
            $params['expiring_base_tax_amount_item'] = $diffBaseTaxAmountItem;
            $params['expiring_discount_amount_magento'] = $diffTaxAmountItem;
            $params['expiring_base_discount_amount_magento'] = $diffBaseTaxAmountItem;
        }

        return $params;
    }

    public function getTrialDays($product)
    {
        $trialDays = $product->getStripeSubTrial();
        if (!empty($trialDays) && is_numeric($trialDays) && $trialDays > 0)
            return $trialDays;

        return 0;
    }

    public function getExpiringCoupon($order)
    {
        $appliedRuleIds = $order->getAppliedRuleIds();
        if (empty($appliedRuleIds))
            return null;

        $appliedRuleIds = explode(",", $appliedRuleIds);

        $foundCoupons = [];
        foreach ($appliedRuleIds as $ruleId)
        {
            $coupon = $this->couponCollection->getByRuleId($ruleId);
            if ($coupon)
                $foundCoupons[] = $coupon;
        }

        if (empty($foundCoupons))
            return null;

        if (count($foundCoupons) > 1)
        {
            $this->paymentsHelper->logError("Could not apply discount coupon: Multiple cart price rules were applied on the cart. Only one can be applied on subscription carts.");
            return null;
        }

        return $foundCoupons[0];
    }

    public function getCouponId($subscriptions)
    {
        if (empty($subscriptions))
            return null;

        $amount = 0;
        $currency = null;
        $coupon = null;

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];
            $coupon = $profile['expiring_coupon'];

            if (empty($coupon['coupon_duration']))
                return null;

            $amount += $profile['discount_amount_stripe'];
            $currency = $profile['currency'];
        }

        if (!$coupon)
            return null;

        $couponId = ((string)$amount) . strtoupper($currency);

        switch ($coupon['coupon_duration'])
        {
            case 'repeating':
                $couponId .= "-months-" . $coupon['coupon_months'];
                break;
            case 'once':
                $couponId .= "-once";
                break;
        }

        if (!empty($this->coupons[$couponId]))
        {
            return $couponId;
        }

        try
        {
            $this->coupons[$couponId] = \Stripe\Coupon::retrieve($couponId);
            return $couponId;
        }
        catch (\Exception $e)
        {
            try
            {
                $params = [
                    'id' => $couponId,
                    'amount_off' => $amount,
                    'currency' => $currency,
                    'name' => "Discount",
                    'duration' => $coupon['coupon_duration']
                ];

                if ($coupon['coupon_duration'] == "repeating" && !empty($coupon['coupon_months']))
                {
                    $params['duration_in_months'] = $coupon['coupon_months'];
                }

                $this->coupons[$couponId] = \Stripe\Coupon::create($params);
                return $couponId;
            }
            catch (\Exception $e)
            {
                $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
                return null;
            }
        }
    }

    public function getSubscriptionTotalFromProfile($profile)
    {
        $subscriptionTotal =
            ($profile['qty'] * $profile['amount_magento']) +
            $profile['shipping_magento'] -
            $profile['discount_amount_magento'];

        if (!$this->config->shippingIncludesTax())
            $subscriptionTotal += $profile['tax_amount_shipping']; // Includes qty calculation

        if (!$this->config->priceIncludesTax())
            $subscriptionTotal += $profile['tax_amount_item']; // Includes qty calculation

        return round(floatval($subscriptionTotal), 2);
    }

    // We increase the subscription price by the amount of the discount, so that we can apply
    // a discount coupon on the amount and go back to the original amount AFTER the discount is applied
    public function getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile)
    {
        $total = $this->getSubscriptionTotalFromProfile($profile);

        if (!empty($profile['expiring_coupon']))
            $total += $profile['discount_amount_magento'];

        return $total;
    }

    public function getStripeDiscountAdjustment($subscriptions)
    {
        $adjustment = 0;

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];

            // This calculation only applies to MixedTrial carts
            if (!$profile['trial_days'])
                return 0;

            if (!empty($profile['expiring_coupon']))
                $adjustment += $profile['discount_amount_stripe'];
        }

        return $adjustment;
    }

    public function updateSubscriptionEntry($subscription, $order)
    {
        $entry = $this->subscriptionFactory->create();
        $entry->load($subscription->id, 'subscription_id');
        $entry->initFrom($subscription, $order);
        $entry->save();
        return $entry;
    }

    public function findSubscriptionItem($sub)
    {
        if (empty($sub->items->data))
            return null;

        foreach ($sub->items->data as $item)
        {
            if (!empty($item->price->product->metadata->{"Type"}) && $item->price->product->metadata->{"Type"} == "Product" && $item->price->type == "recurring")
                return $item;
        }

        return null;
    }

    public function isStripeCheckoutSubscription($sub)
    {
        if (empty($sub->metadata->{"Order #"}))
            return false;

        $order = $this->paymentsHelper->loadOrderByIncrementId($sub->metadata->{"Order #"});

        if (!$order || !$order->getId())
            return false;

        return $this->paymentsHelper->isStripeCheckoutMethod($order->getPayment()->getMethod());
    }

    public function formatSubscriptionName($sub)
    {
        $name = "";

        if (empty($sub))
            return "Unknown subscription (err: 1)";

        // Subscription Items
        if ($this->isStripeCheckoutSubscription($sub))
        {
            $item =  $this->findSubscriptionItem($sub);

            if (!$item)
                return "Unknown subscription (err: 2)";

            if (!empty($item->price->product->name))
                $name = $item->price->product->name;
            else
                return "Unknown subscription (err: 3)";

            $currency = $item->price->currency;
            $amount = $item->price->unit_amount;
            $quantity = $item->quantity;
        }
        // Invoice Items
        else
        {
            if (!empty($sub->plan->name))
                $name = $sub->plan->name;

            if (empty($name) && isset($sub->plan->product) && is_numeric($sub->plan->product))
            {
                $product = $this->paymentsHelper->loadProductById($sub->plan->product);
                if ($product && $product->getName())
                    $name = $product->getName();
            }
            else
                return "Unknown subscription (err: 4)";

            $currency = $sub->plan->currency;
            $amount = $sub->plan->amount;
            $quantity = $sub->quantity;
        }

        $precision = PriceCurrencyInterface::DEFAULT_PRECISION;
        $cents = 100;
        $qty = '';

        if ($this->paymentsHelper->isZeroDecimal($currency))
        {
            $cents = 1;
            $precision = 0;
        }

        $amount = $amount / $cents;

        if ($quantity > 1)
        {
            $qty = " x " . $quantity;
        }

        $this->priceCurrency->getCurrency()->setCurrencyCode(strtoupper($currency));
        $cost = $this->priceCurrency->format($amount, false, $precision);

        return "$name ($cost$qty)";
    }

    public function getSubscriptionsName($subscriptions)
    {
        $productNames = [];

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription['profile'];

            if ($profile['qty'] > 1)
                $productNames[] = $profile['qty'] . " x " . $profile['name'];
            else
                $productNames[] = $profile['name'];
        }

        $productName = implode(", ", $productNames);

        $productName = substr($productName, 0, 250);

        return $productName;
    }

    public function createSubscriptionPriceForSubscriptions(\Magento\Quote\Api\Data\CartInterface $quote, $subscriptions)
    {
        if (empty($quote->getId()))
            $quote = $this->paymentsHelper->saveQuote($quote);

        if (empty($quote->getId()))
            throw new \Exception("Cannot create subscription price from a quote with no ID.");

        if (empty($subscriptions))
            throw new \Exception("No subscriptions specified");

        $combinedProfile = $this->getCombinedProfileFromSubscriptions($subscriptions);

        $productNames = [];
        $interval = $combinedProfile['interval'];
        $intervalCount = $combinedProfile['interval_count'];
        $currency = $combinedProfile['currency'];
        $productName = $combinedProfile['name'];

        if ($this->paymentsHelper->isMultiShipping())
            throw new \Exception("Price ID for multi-shipping subscriptions is not implemented", 1);

        $priceId = $quote->getId();

        $productData = [
            "name" => $productName
        ];

        $priceData = ([
            'unit_amount' => $combinedProfile['stripe_amount'],
            'currency' => $currency,
            'recurring' => [
                'interval' => $interval,
                'interval_count' => $intervalCount
            ],
            'product_data' => $productData,
        ]);

        $key = "price_data_quote_" . $quote->getId();

        try
        {
            $oldData = $this->cache->load($key);
            if (empty($oldData))
                throw new \Exception("Not found");

            $oldData = json_decode($oldData, true);
            if (empty($oldData["price_id"]) || empty($oldData["price_data"]))
                throw new \Exception("Invalid data");

            if ($this->compare->isDifferent($oldData["price_data"], $priceData))
                throw new \Exception("Price has changed");

            return $this->config->getStripeClient()->prices->retrieve($oldData["price_id"]);
        }
        catch (\Exception $e)
        {

        }

        $stripePrice = $this->config->getStripeClient()->prices->create($priceData);

        $data = [
            "price_id" => $stripePrice->id,
            "price_data" => $priceData
        ];
        $this->cache->save(json_encode($data), $key, $tags = ["unconfirmed_subscriptions"], $lifetime = 2 * 60 * 60);

        return $stripePrice;
    }


    public function createPriceForOneTimePayment($quote, $paymentIntentParams, $stripeDiscountAdjustment = 0)
    {
        if (empty($quote->getId()))
            $quote = $this->paymentsHelper->saveQuote($quote);

        if (empty($quote->getId()))
            throw new \Exception("Cannot create price from a quote with no ID.");

        $productData = [
            "name" => __("One time payment")
        ];

        $currency = $paymentIntentParams['currency'];
        $totalAmount = $paymentIntentParams['amount'] + $stripeDiscountAdjustment;

        $priceData = ([
            'unit_amount' => $totalAmount,
            'currency' => $currency,
            'product_data' => $productData,
        ]);

        $key = "price_data_quote_once_" . $quote->getId();

        try
        {
            $oldData = $this->cache->load($key);
            if (empty($oldData))
                throw new \Exception("Not found");

            $oldData = json_decode($oldData, true);
            if (empty($oldData["price_id"]) || empty($oldData["price_data"]))
                throw new \Exception("Invalid data");

            if ($this->compare->isDifferent($oldData["price_data"], $priceData))
                throw new \Exception("Price has changed");

            return $this->config->getStripeClient()->prices->retrieve($oldData["price_id"]);
        }
        catch (\Exception $e)
        {

        }

        $stripePrice = $this->config->getStripeClient()->prices->create($priceData);

        $data = [
            "price_id" => $stripePrice->id,
            "price_data" => $priceData
        ];
        $this->cache->save(json_encode($data), $key, $tags = ["unconfirmed_subscriptions"], $lifetime = 2 * 60 * 60);

        return $stripePrice;
    }

    public function collectMetadataForSubscriptions($quote, $subscriptions, $order = null)
    {
        $subscriptionProductIds = [];

        foreach ($subscriptions as $subscription)
        {
            $product = $subscription['product'];
            $profile = $subscription['profile'];
            $subscriptionProductIds[] = $profile['product_id'];
        }

        if (empty($subscriptionProductIds))
            throw new \Exception("Could not find any subscription product IDs in cart subscriptions.");

        $metadata = [
            "Type" => "SubscriptionsTotal",
            "SubscriptionProductIDs" => implode(",", $subscriptionProductIds)
        ];

        if ($order && $order->getIncrementId())
            $metadata["Order #"] = $order->getIncrementId();
        else if ($quote->getReservedOrderId())
            $metadata["Order #"] = $quote->getReservedOrderId();

        return $metadata;
    }

    public function getTrialingSubscriptionsAmounts($quote = null)
    {
        if ($this->trialingSubscriptionsAmounts)
            return $this->trialingSubscriptionsAmounts;

        if (!$quote)
            $quote = $this->paymentsHelper->getQuote();

        $trialingSubscriptionsAmounts = [
            "subscriptions_total" => 0,
            "base_subscriptions_total" => 0,
            "shipping_total" => 0,
            "base_shipping_total" => 0,
            "discount_total" => 0,
            "base_discount_total" => 0,
            "tax_total" => 0,
            "base_tax_total" => 0
        ];

        if (!$quote)
            return $trialingSubscriptionsAmounts;

        $this->trialingSubscriptionsAmounts = $trialingSubscriptionsAmounts;

        $items = $quote->getAllItems();
        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromOrderItem($item);

            if (!$this->isSubscriptionProduct($product))
                continue;

            $trial = $product->getStripeSubTrial();
            if (is_numeric($trial) && $trial > 0)
            {
                $item = $this->paymentsHelper->getSubscriptionQuoteItemWithTotalsFrom($item, $quote);

                $profile = $this->getSubscriptionDetails($product, $quote, $item);

                $shipping = $profile["shipping_magento"];
                $baseShipping = $profile["base_shipping_magento"];
                if ($this->config->shippingIncludesTax())
                {
                    // $shipping -= $profile["tax_amount_shipping"];
                    // $baseShipping -= $baseProfile["tax_amount_shipping"];
                }

                $subtotal = $item->getRowTotal();
                $baseSubtotal = $item->getBaseRowTotal();
                if ($this->config->priceIncludesTax())
                {
                    $subtotal = $item->getRowTotalInclTax();
                    $baseSubtotal = $item->getBaseRowTotalInclTax();
                }

                $discountTotal = $profile["discount_amount_magento"] - $profile['expiring_discount_amount_magento'];
                $baseDiscountTotal = $profile["base_discount_amount_magento"] - $profile['expiring_base_discount_amount_magento'];

                $taxAmountItem = $profile["tax_amount_item"] - $profile['expiring_tax_amount_item'];
                $baseTaxAmountItem = $profile["base_tax_amount_item"] - $profile['expiring_base_tax_amount_item'];

                $taxAmountShipping = $profile["tax_amount_shipping"];
                $baseTaxAmountShipping = $profile["base_tax_amount_shipping"];

                $this->trialingSubscriptionsAmounts["subscriptions_total"] += $subtotal;
                $this->trialingSubscriptionsAmounts["base_subscriptions_total"] += $baseSubtotal;
                $this->trialingSubscriptionsAmounts["shipping_total"] += $shipping;
                $this->trialingSubscriptionsAmounts["base_shipping_total"] += $baseShipping;
                $this->trialingSubscriptionsAmounts["discount_total"] += $discountTotal;
                $this->trialingSubscriptionsAmounts["base_discount_total"] += $baseDiscountTotal;
                $this->trialingSubscriptionsAmounts["tax_total"] += $taxAmountItem + $taxAmountShipping;
                $this->trialingSubscriptionsAmounts["base_tax_total"] += $baseTaxAmountItem + $baseTaxAmountShipping;

                $inclusiveTax = $baseInclusiveTax = 0;
                if ($this->config->shippingIncludesTax())
                {
                    $inclusiveTax += $taxAmountShipping;
                    $baseInclusiveTax = $baseTaxAmountShipping;
                }

                if ($this->config->priceIncludesTax())
                {
                    $inclusiveTax += $taxAmountItem;
                    $baseInclusiveTax = $baseTaxAmountItem;
                }
                $this->trialingSubscriptionsAmounts["tax_inclusive"] = $inclusiveTax;
                $this->trialingSubscriptionsAmounts["base_tax_inclusive"] = $baseInclusiveTax;
            }
        }

        foreach ($this->trialingSubscriptionsAmounts as $key => $amount)
        {
            $this->trialingSubscriptionsAmounts[$key] = round($amount, 2);
        }

        return $this->trialingSubscriptionsAmounts;
    }

    public function formatInterval($stripeAmount, $currency, $intervalCount, $intervalUnit)
    {
        $amount = $this->paymentsHelper->formatStripePrice($stripeAmount, $currency);

        if ($intervalCount > 1)
            return __("%1 every %2 %3", $amount, $intervalCount, $intervalUnit . "s");
        else
            return __("%1 every %2", $amount, $intervalUnit);
    }

    public function renewTogether($subscriptions)
    {
        $startingTimes = [];
        $endingTimes = [];
        $now = time();

        foreach ($subscriptions as $subscription)
        {
            $starts = $now;
            if (!empty($subscription['profile']['trial_end']))
                $starts = $subscription['profile']['trial_end'];
            else if (!empty($subscription['profile']['trial_days']))
                $starts = strtotime("+" . $subscription['profile']['trial_days'] . " days", $now);

            $ends = $starts + strtotime("+" . $subscription['profile']['interval_count'] . " " . $subscription['profile']['interval']);

            $startingTimes[$starts] = $starts;
            $endingTimes[$ends] = $ends;
        }

        if (count($startingTimes) > 1)
            return false;

        if (count($endingTimes) > 1)
            return false;

        return true;
    }

    public function hasMultipleSubscriptionProducts(array $products)
    {
        if (!$this->paymentsHelper->isSubscriptionsEnabled())
            return false;

        $found = false;

        foreach ($products as $product)
        {
            if (!$this->isSubscriptionProduct($product))
                continue;

            if ($found)
                return true;

            $found = true;
        }

        return false;
    }

    public function checkIfAddToCartIsSupported(
        \Magento\Quote\Model\Quote $quote,
        ?\Magento\Catalog\Model\Product $product)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        if (!$this->isSubscriptionProduct($product))
            return;

        $products = [ $product ];

        foreach ($quote->getAllItems() as $quoteItem)
        {
            if (is_numeric($quoteItem->getProductId()))
            {
                $product = $this->paymentsHelper->loadProductById($quoteItem->getProductId());
                if ($product && $product->getId())
                {
                    $products[] = $product;
                }
            }
        }

        if ($this->hasMultipleSubscriptionProducts($products))
        {
            throw new LocalizedException(__("Only one subscription is allowed per order."));
        }
    }

    public function getTrialSubscriptionsFrom($items)
    {
        $results = [];

        if (!$this->config->isSubscriptionsEnabled())
            return $results;

        foreach ($items as $item)
        {
            $product = $this->paymentsHelper->getSubscriptionProductFromOrderItem($item);
            if (!$product)
                continue;

            $trial = $product->getStripeSubTrial();
            if (is_numeric($trial) && $trial > 0)
            {
                $results[] = [
                    'order_item' => $item,
                    'product' => $product
                ];
            }
        }

        return $results;
    }

    public function createQuoteFromOrder($originalOrder)
    {
        $recurringOrder = $this->recurringOrderFactory->create();
        $quote = $recurringOrder->createQuoteFrom($originalOrder);
        $recurringOrder->setQuoteCustomerFrom($originalOrder, $quote);
        $recurringOrder->setQuoteAddressesFrom($originalOrder, $quote);

        $invoiceDetails = [
            'products' => []
        ];

        foreach ($originalOrder->getAllItems() as $orderItem)
        {
            $product = $this->paymentsHelper->loadProductById($orderItem->getProductId());

            if ($this->isSubscriptionProduct($product))
            {
                $invoiceDetails['products'][$orderItem->getProductId()] = [
                    'amount' => $orderItem->getPrice(),
                    'base_amount' => $orderItem->getBasePrice(),
                    'qty' => $orderItem->getQtyOrdered()
                ];
            }
        }

        if (empty($invoiceDetails['products']))
        {
            throw new \Exception("Order #" . $originalOrder->getIncrementId() . " does not include any subscriptions.");
        }

        $recurringOrder->setQuoteItemsFrom($originalOrder, $invoiceDetails, $quote);
        $recurringOrder->setQuoteShippingMethodFrom($originalOrder, $quote);
        $recurringOrder->setQuoteDiscountFrom($originalOrder, $quote, null);
        $recurringOrder->setQuotePaymentMethodFrom($originalOrder, $quote);

        // Collect Totals & Save Quote
        $quote->setTotalsCollectedFlag(false)->collectTotals();
        return $quote;
    }

    public function getSubscriptionProductIDs(\Stripe\Subscription $subscription)
    {
        $productIDs = [];

        if (isset($subscription->metadata->{"Product ID"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"Product ID"});
        }
        else if (isset($subscription->metadata->{"SubscriptionProductIDs"}))
        {
            $productIDs = explode(",", $subscription->metadata->{"SubscriptionProductIDs"});
        }

        return $productIDs;
    }

    public function getSubscriptionOrderID(\Stripe\Subscription $subscription)
    {
        if (isset($subscription->metadata->{"Order #"}))
        {
            return $subscription->metadata->{"Order #"};
        }

        return null;
    }

    public function isSubscriptionProduct(
        ?\Magento\Catalog\Api\Data\ProductInterface $product
    )
    {
        if (!$product || !$product->getId())
            return false;

        if (!$product->getStripeSubEnabled())
            return false;

        $productType = $product->getTypeId();
        if (!in_array($productType, ['simple', 'virtual']))
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

    public function getConfigurableSubscriptionProductFrom(\Stripe\Subscription $subscription)
    {
        $orderIncrementId = $this->getSubscriptionOrderID($subscription);

        $order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$order)
            return null;

        $productIds = $this->getSubscriptionProductIDs($subscription);

        foreach ($order->getAllItems() as $orderItem)
        {
            if (in_array($orderItem->getProductId(), $productIds))
            {
                if ($orderItem->getParentItem() && $orderItem->getParentItem()->getProductType() == "configurable")
                {
                    $this->configurableProductIds[$subscription->id][$orderItem->getProductId()] = $orderItem->getParentItem()->getProductId();
                }
            }
        }

        foreach ($this->configurableProductIds[$subscription->id] as $productId)
        {
            return $this->paymentsHelper->loadProductById($productId);
        }

        return null;
    }

    public function getConfigurableSubscriptionQty(\Stripe\Subscription $subscription): int
    {
        $orderIncrementId = $this->getSubscriptionOrderID($subscription);
        $order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$order)
            return 0;

        $product = $this->getConfigurableSubscriptionProductFrom($subscription);
        if (!$product)
            return 0;

        foreach ($order->getAllItems() as $orderItem)
        {
            if ($orderItem->getProductId() == $product->getId())
                return (int)$orderItem->getQtyOrdered();
        }

        return 0;
    }

    public function getConfigurableSubscriptionSuperAttribute($subscription)
    {
        $orderIncrementId = $this->getSubscriptionOrderID($subscription);
        $order = $this->paymentsHelper->loadOrderByIncrementId($orderIncrementId);
        if (!$order)
            return null;

        $configurableProduct = $this->getConfigurableSubscriptionProductFrom($subscription);
        if ($configurableProduct)
        {
            $allowAttributes = $configurableProduct->getTypeInstance()->getConfigurableAttributes($configurableProduct);
            foreach ($allowAttributes as $attribute)
            {
                return $attribute;
            }
        }

        return null;
    }

    public function getInvoiceAmount(\Stripe\Subscription $subscription)
    {
        $total = 0;
        $currency = null;

        if (empty($subscription->items->data))
            return __("Billed");

        foreach ($subscription->items->data as $item)
        {
            $amount = 0;
            $qty = $item->quantity;

            if (!empty($item->price->type) && $item->price->type != "recurring")
                continue;

            if (!empty($item->price->unit_amount))
                $amount = $qty * $item->price->unit_amount;

            if (!empty($item->price->currency))
                $currency = $item->price->currency;

            if (!empty($item->tax_rates[0]->percentage))
            {
                $rate = 1 + $item->tax_rates[0]->percentage / 100;
                $amount = $rate * $amount;
            }

            $total += $amount;
        }

        return $this->paymentsHelper->formatStripePrice($total, $currency);
    }

    public function formatDelivery(\Stripe\Subscription $subscription)
    {
        $interval = $subscription->plan->interval;
        $count = $subscription->plan->interval_count;

        if ($count > 1)
            return __("every %1 %2", $count, __($interval . "s"));
        else
            return __("every %1", __($interval));
    }

    public function formatLastBilled(\Stripe\Subscription $subscription)
    {
        $startDate = $subscription->created;

        if ($subscription->status == "trialing")
        {
            $startDate = $subscription->current_period_end;
        }

        $wasUpdated = (empty($subscription->metadata["Order #"]) && !empty($subscription->metadata["Original Order #"]));
        $date = $subscription->current_period_start;

        if ($wasUpdated)
        {
            $date = $subscription->current_period_end;
            $day = date("j", $date);
            $sup = date("S", $date);
            $month = date("F", $date);

            return __("starting on %1<sup>%2</sup>&nbsp;%3", $day, $sup, $month);
        }
        else if ($startDate > $date)
        {
            $day = date("j", $startDate);
            $sup = date("S", $startDate);
            $month = date("F", $startDate);

            return __("trialing until %1<sup>%2</sup> %3", $day, $sup, $month);
        }
        else
        {
            $day = date("j", $date);
            $sup = date("S", $date);
            $month = date("F", $date);

            return __("last billed %1<sup>%2</sup>&nbsp;%3", $day, $sup, $month);
        }
    }

    public function getUpcomingInvoice($prorationTimestamp = null)
    {
        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();
        if (!$subscriptionUpdateDetails)
            return null;

        if (!$prorationTimestamp)
        {
            if (!empty($subscriptionUpdateDetails['_data']['proration_timestamp']))
            {
                $prorationTimestamp = $subscriptionUpdateDetails['_data']['proration_timestamp'];
            }
            else
            {
                $prorationTimestamp = $subscriptionUpdateDetails['_data']['proration_timestamp'] = time();
                $checkoutSession->setSubscriptionUpdateDetails($subscriptionUpdateDetails);
            }
        }

        $items = [];
        if ($subscriptionUpdateDetails && !empty($subscriptionUpdateDetails['_data']['subscription_id']))
        {
            $oldSubscriptionId = $subscriptionUpdateDetails['_data']['subscription_id'];
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($oldSubscriptionId);
            $invoicePreview = $stripeSubscriptionModel->getUpcomingInvoiceAfterUpdate($prorationTimestamp);
            $oldPrice = $invoicePreview->oldPriceId;
            $newPrice = $invoicePreview->newPriceId;
            $quote = $this->paymentsHelper->getQuote();
            $remainingAmount = $unusedAmount = $subscriptionAmount = 0;
            $remainingLineItem = null;
            $labels = [
                'remaining' => null,
                'unused' => null,
                'subscription' => null
            ];

            $comments = [];

            foreach ($invoicePreview->lines->data as $invoiceItem)
            {
                $invoiceItemMagentoAmount = $this->paymentsHelper->formatStripePrice($invoiceItem->amount, $invoiceItem->currency);
                if ($invoiceItemMagentoAmount == "-")
                {
                    // Add negative amount at the end
                    $comments[] = $invoiceItemMagentoAmount . " " . lcfirst($invoiceItem->description);
                }
                else
                {
                    // Add positive amounts at the beginning
                    array_unshift($comments, $invoiceItemMagentoAmount . " " . lcfirst($invoiceItem->description));
                }

                if ($invoiceItem->type == "subscription")
                {
                    $subscriptionAmount += $invoiceItem->amount;
                    $labels['subscription'] = $this->formatInterval(
                        $subscriptionAmount,
                        $invoiceItem->currency,
                        $invoiceItem->price->recurring->interval_count,
                        $invoiceItem->price->recurring->interval
                    );
                }
                else if ($invoiceItem->amount < 0)
                {
                    $unusedAmount += $invoiceItem->amount;
                    $labels['unused'] = $this->paymentsHelper->formatStripePrice($unusedAmount, $invoiceItem->currency);
                }
                else if ($invoiceItem->amount > 0)
                {
                    $remainingAmount += $invoiceItem->amount;
                    $remainingLineItem = $invoiceItem;
                    $labels['remaining'] = $this->paymentsHelper->formatStripePrice($remainingAmount, $invoiceItem->currency);
                    if (empty($labels['subscription']))
                    {
                        $labels['subscription'] = $this->formatInterval(
                            $remainingAmount,
                            $invoiceItem->currency,
                            $invoiceItem->price->recurring->interval_count,
                            $invoiceItem->price->recurring->interval
                        );
                    }
                }
            }

            // Update the order comments
            if (empty($comments))
            {
                $subscriptionUpdateDetails['_data']['comments'] = null;
            }
            else
            {
                $subscriptionUpdateDetails['_data']['comments'] = implode(", ", $comments);
            }

            $checkoutSession->setSubscriptionUpdateDetails($subscriptionUpdateDetails);

            if ($unusedAmount < 0)
            {
                $items["unused_time"] = [
                    "amount" => $this->paymentsHelper->convertStripeAmountToQuoteAmount($unusedAmount, $invoicePreview->currency, $quote),
                    "currency" => $invoicePreview->currency,
                    "label" => $labels['unused']
                ];
            }

            if ($remainingAmount > 0)
            {
                $items["proration_fee"] = [
                    "amount" => $this->paymentsHelper->convertStripeAmountToQuoteAmount($remainingAmount, $invoicePreview->currency, $quote),
                    "currency" => $invoicePreview->currency,
                    "label" => $labels['remaining']
                ];
            }

            if ($subscriptionAmount > 0)
            {
                $items["new_price"] = [
                    "amount" => $this->paymentsHelper->convertStripeAmountToQuoteAmount($subscriptionAmount, $invoicePreview->currency, $quote),
                    "currency" => $invoicePreview->currency,
                    "label" => $labels['subscription']
                ];
            }
            else if ($remainingAmount > 0 && $remainingLineItem)
            {
                // This is the case where the customer updates the subscription at the same time they bought it. Because
                // the remaining amount equals the subscription amount, the Stripe API does not return the subscription line item
                // so we have to use the remaining amount to build it.
                $items["new_price"] = [
                    "amount" => $this->paymentsHelper->convertStripeAmountToQuoteAmount($remainingAmount, $invoicePreview->currency, $quote),
                    "currency" => $invoicePreview->currency,
                    "label" => $labels['subscription']
                ];
            }

            $stripeBalance = min($invoicePreview->amount_remaining, $invoicePreview->total);
            if (!empty($stripeBalance))
            {
                $magentoBalance = $this->paymentsHelper->convertStripeAmountToQuoteAmount($stripeBalance, $invoicePreview->currency, $quote);
                $magentoBaseBalance = $this->paymentsHelper->convertStripeAmountToBaseQuoteAmount($stripeBalance, $invoicePreview->currency, $quote);

                // These will be added to the order grand total
                $items["proration_adjustment"] = max(0, $magentoBalance) - $quote->getGrandTotal();
                $items["base_proration_adjustment"] = max(0, $magentoBaseBalance) - $quote->getBaseGrandTotal();
            }

            if (!empty($items))
            {
                return $items;
            }
        }

        return null;
    }

    public function isSubscriptionUpdate()
    {
        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $updateDetails = $checkoutSession->getSubscriptionUpdateDetails();

        return !empty($updateDetails['_data']['subscription_id']);
    }

    public function updateSubscription(\Magento\Payment\Model\InfoInterface $payment)
    {
        try
        {
            $checkoutSession = $this->paymentsHelper->getCheckoutSession();
            $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();
            if (!$subscriptionUpdateDetails || empty($subscriptionUpdateDetails['_data']['subscription_id']))
                throw new \Exception("The subscription update details could not be read from the checkout session.");

            $items = [];
            $oldSubscriptionId = $subscriptionUpdateDetails['_data']['subscription_id'];
            $stripeSubscriptionModel = $this->stripeSubscriptionFactory->create()->fromSubscriptionId($oldSubscriptionId);
            $stripeSubscriptionModel->performUpdate($payment);
        }
        catch (LocalizedException $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            throw $e;
        }
        catch (\Exception $e)
        {
            $this->paymentsHelper->logError($e->getMessage(), $e->getTraceAsString());
            throw new LocalizedException(__("Sorry, the order could not be placed. Please contact us for assistance."));
        }
    }

    public function cancelSubscriptionUpdate($silent = false)
    {
        if (!$this->config->isSubscriptionsEnabled())
            return;

        $checkoutSession = $this->paymentsHelper->getCheckoutSession();
        $subscriptionUpdateDetails = $checkoutSession->getSubscriptionUpdateDetails();

        if (!$subscriptionUpdateDetails)
            return;

        $productNames = [];
        $quote = $this->paymentsHelper->getQuote();
        $quoteItems = $quote->getAllVisibleItems();
        foreach ($quoteItems as $quoteItem)
        {
            $productNames[] = $quoteItem->getName();
            $quoteItem->delete();
        }
        $this->paymentsHelper->saveQuote($quote);

        if (!$silent)
        {
            if (!empty($productNames))
            {
                $this->paymentsHelper->addWarning(__("The subscription update (%1) has been canceled.", implode(", ", $productNames)));
            }
            else
            {
                $this->paymentsHelper->addWarning(__("The subscription update has been canceled."));
            }
        }

        $checkoutSession->unsSubscriptionUpdateDetails();
    }

    public function loadSubscriptionModelBySubscriptionId($subscriptionId)
    {
        return $this->subscriptionCollectionFactory->create()->getBySubscriptionId($subscriptionId);
    }

    // Returns a minimal profile with just price data
    public function getCombinedProfileFromSubscriptions($subscriptions)
    {
        $combinedProfile = [
            "name" => $this->getSubscriptionsName($subscriptions),
            "magento_amount" => 0,
            "stripe_amount" => null,
            "interval" => null,
            "interval_count" => null,
            "currency" => null,
            "product_ids" => []
        ];

        foreach ($subscriptions as $subscription)
        {
            $profile = $subscription["profile"];

            if (empty($combinedProfile["currency"]))
            {
                $combinedProfile["currency"] = $profile["currency"];
            }
            else if ($combinedProfile["currency"] != $profile["currency"])
            {
                throw new \Exception("It is not possible to buy multiple subscriptions in different currencies.");
            }

            if (empty($combinedProfile["interval"]))
            {
                $combinedProfile["interval"] = $profile["interval"];
            }
            else if ($combinedProfile["interval"] != $profile["interval"])
            {
                throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));
            }

            if (empty($combinedProfile["interval_count"]))
            {
                $combinedProfile["interval_count"] = $profile["interval_count"];
            }
            else if ($combinedProfile["interval_count"] != $profile["interval_count"])
            {
                throw new LocalizedException(__("Subscriptions that do not renew together must be bought separately."));
            }

            $combinedProfile["magento_amount"] += $this->getSubscriptionTotalWithDiscountAdjustmentFromProfile($profile);
            $combinedProfile["product_ids"][] = $profile["product_id"];
        }

        if (!$combinedProfile["currency"])
            throw new \Exception("No subscriptions specified.");

        $combinedProfile["stripe_amount"] = $this->paymentsHelper->convertMagentoAmountToStripeAmount($combinedProfile["magento_amount"], $combinedProfile["currency"]);

        return $combinedProfile;
    }

    // The canonical amount is the cost over a fixed 30-day period.
    // We do not use months because they have a different amount of days.
    // So do leap years, but we can live with that.
    public function getCanonicalAmount($stripeAmount, $interval, $intervalCount): float
    {
        $canonicalAmount = 0.0;
        $stripeAmount = (float)$stripeAmount;

        if (!is_numeric($stripeAmount))
            throw new \Exception("A non-numeric Stripe amount was passed as a parameter.");

        if (!is_numeric($intervalCount))
            throw new \Exception("A non-numeric interval count was passed as a parameter.");

        switch ($interval)
        {
            case "day":

                $canonicalAmount += (($stripeAmount / $intervalCount) * 30);
                break;

            case "week":

                $canonicalAmount += ((($stripeAmount / $intervalCount) / 7) * 30);
                break;

            case "month":

                $canonicalAmount += (((($stripeAmount / $intervalCount) * 12) / 365) * 30);
                break;

            case "year":

                $canonicalAmount += ((($stripeAmount / $intervalCount) / 365) * 30);
                break;

            default:
                break;
        }

        return (float)$canonicalAmount;
    }

    public function hasExpiringDiscountCoupons()
    {
        $quote = $this->paymentsHelper->getQuote();
        $subscriptions = $this->getSubscriptionsFromQuote($quote);
        foreach ($subscriptions as $subscription)
        {
            if (!empty($subscription['profile']['expiring_coupon']))
                return true;
        }
        return false;
    }

    public function isZeroAmountOrder($order)
    {
        $orderItems = $order->getAllItems();
        $trialSubscriptions = [];
        foreach ($orderItems as $orderItem)
        {
            $productModel = $this->subscriptionProductFactory->create()->fromOrderItem($orderItem);

            if ($productModel->isSubscriptionProduct() && $productModel->hasTrialPeriod())
            {
                $trialSubscriptions[] = [
                    'product' => $productModel->getProduct(),
                    'order_item' => $orderItem,
                    'profile' => $this->getSubscriptionDetails($productModel->getProduct(), $order, $orderItem),
                ];
            }
        }

        if (count($trialSubscriptions) > 0)
        {
            $combinedProfile = $this->getCombinedProfileFromSubscriptions($trialSubscriptions);

            $charge = $order->getGrandTotal() - $combinedProfile['magento_amount'];

            return ($charge < 0.005);
        }
        else
        {
            return false;
        }
    }

}
