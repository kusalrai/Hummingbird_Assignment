<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableVirtualSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class DowngradeTest extends \PHPUnit\Framework\TestCase
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

    /**
     * @magentoConfigFixture current_store payment/stripe_payments_subscriptions/upgrade_downgrade 1
     * @magentoConfigFixture current_store payment/stripe_payments_subscriptions/prorations_upgrades 1
     * @magentoConfigFixture current_store payment/stripe_payments_subscriptions/prorations_downgrades 1
     */
    public function testDowngrades()
    {
        $virtualMonthlySubscription = $this->tests->getProduct("virtual-monthly-subscription-product");
        $virtualQuarterlySubscription = $this->tests->getProduct("virtual-quarterly-subscription-product");

        $this->quote->create()
            ->addProduct('configurable-virtual-subscription', 2, [["subscription" => "monthly"]])
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
                "SubscriptionProductIDs" => $virtualMonthlySubscription->getId(),
                "Type" => "SubscriptionsTotal"
            ],
            "status" => "active"
        ]);
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);
        $this->assertEquals($order->getGrandTotal() * 100, $charges->data[0]->amount_captured);

        // Downgrade from monthly to quarterly with prorations
        $this->quote->create()->loginOpc()->save();
        $request = $this->subscriptionsController->getRequest();
        $request->setParam("update", $subscription->id);
        $buyRequest = $this->getBuyRequest($order);

        foreach ($buyRequest['super_attribute'] as $attributeId => $selection)
        {
            $buyRequest['super_attribute'][$attributeId] = "quarterly"; // Change from monthly to quarterly
        }

        $request->setParam("super_attribute", $buyRequest["super_attribute"]);
        $request->setParam("qty", 1);
        $this->subscriptionsController->execute();

        $this->quote
            ->setBillingAddress("California")
            ->setPaymentMethod("SubscriptionUpdate");

        $this->tests->startWebhooks();
        $billingAddress = $this->quote->getQuote()->getBillingAddress()->getData();
        $shippingAddress = null;
        $shippingMethod = null;
        $this->service->update_subscription($billingAddress, $shippingAddress, $shippingMethod, $couponCode = null);
        $this->tests->runWebhooks();

        // Check if a partial credit memo was created for the 1st order
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize());
        $creditmemo = $order->getCreditmemosCollection()->getFirstItem();

        $totalRefunded = 10.82; // $10 for the subscription, $0.83 for tax, -$0.01 for proration usage

        $this->tests->compare($order->getData(), [
            "total_invoiced" => $order->getGrandTotal(),
            "total_paid" => $order->getGrandTotal(),
            "total_refunded" => $totalRefunded,
            "state" => "complete",
            "status" => "complete"
        ]);

        $this->assertEquals($totalRefunded, $creditmemo->getGrandTotal());
        $this->assertEquals(1, $creditmemo->getEmailSent());
        $this->assertTrue($this->tests->orderHasComment($order, "Successfully updated subscription upon customer request."));
        $this->assertTrue($this->tests->orderHasComment($order, "The customer has an unused credit balance of"));
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
