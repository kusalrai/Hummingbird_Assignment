<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class UpgradeTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->subscriptionsController = $this->objectManager->get(\StripeIntegration\Payments\Controller\Customer\Subscriptions::class);
        $this->dataHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Data::class);
        $this->service = $this->objectManager->get(\StripeIntegration\Payments\Api\Service::class);
    }

    public function testUpgradesDowngrades()
    {
        $simpleMonthlySubscription = $this->tests->getProduct("simple-monthly-subscription-product");

        $this->quote->create()
            ->addProduct('configurable-subscription', 1, [["subscription" => "monthly"]])
            ->loginOpc()
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();
        $ordersCount = $this->tests->getOrdersCount();
        $this->tests->confirmSubscription($order);

        // Stripe checks
        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $order->getGrandTotal() * 100
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Order #" => $order->getIncrementId(),
                "SubscriptionProductIDs" => $simpleMonthlySubscription->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active"
        ]);
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);
        $this->assertEquals($order->getGrandTotal() * 100, $charges->data[0]->amount_captured);

        // Upgrade from 1 to 2
        $this->quote->create()->loginOpc()->save();
        $request = $this->subscriptionsController->getRequest();
        $request->setParam("update", $subscription->id);
        $buyRequest = $this->getBuyRequest($order);
        $request->setParam("super_attribute", $buyRequest["super_attribute"]);
        $request->setParam("qty", 2);
        $this->subscriptionsController->execute();

        $this->quote
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SubscriptionUpdate");

        $this->tests->startWebhooks();
        $billingAddress = $this->quote->getQuote()->getBillingAddress()->getData();
        $shippingAddress = $this->quote->getQuote()->getShippingAddress()->getData();
        $shippingMethod = "flatrate_flatrate";
        $this->service->update_subscription($billingAddress, $shippingAddress, $shippingMethod, $couponCode = null);
        $this->tests->runWebhooks();

        // Stripe checks
        $customer = $this->tests->stripe()->customers->retrieve($customerId);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEquals($order->getGrandTotal(), $this->quote->getQuote()->getGrandTotal());
        $this->tests->compare($subscription, [
            "items" => [
                "data" => [
                    0 => [
                        "plan" => [
                            "amount" => $this->quote->getQuote()->getGrandTotal() * 100
                        ]
                    ]
                ]
            ],
            "metadata" => [
                "Original Order #" => $order->getIncrementId(),
                "Order #" => "unset",
                "SubscriptionProductIDs" => $simpleMonthlySubscription->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active"
        ]);

        // Check if recurring orders work
        $ordersCount = $this->tests->getOrdersCount();
        $invoice = $this->tests->stripe()->invoices->retrieve($customer->subscriptions->data[0]->latest_invoice);
        $this->tests->event()->trigger("invoice.payment_succeeded", $invoice, [
            'billing_reason' => 'subscription_cycle'
        ]);

        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check the new order
        $recurringOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getGrandTotal(), $recurringOrder->getGrandTotal());
    }

    public function getBuyRequest($order)
    {
        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $buyRequest = $this->dataHelper->getConfigurableProductBuyRequest($orderItem);
            return $buyRequest;
        }

        throw new \Exception("No buyRequest found for the order");
    }
}
