<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\ConfigurableVirtualSubscription;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class UpgradesDowngradesTest extends \PHPUnit\Framework\TestCase
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
    public function testUpgradesDowngrades()
    {
        $calculation = $this->objectManager->get(\Magento\Tax\Model\Calculation::class);
        $this->quote->create()
            ->addProduct('configurable-virtual-subscription', 1, [["subscription" => "quarterly"]])
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
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId]);
        $this->assertCount(1, $charges->data);
        $this->assertEquals($order->getGrandTotal() * 100, $charges->data[0]->amount_captured);

        // Upgrade from quarterly to monthly with prorations
        $this->quote->create()->loginOpc()->save();
        $request = $this->subscriptionsController->getRequest();
        $request->setParam("update", $subscription->id);
        $buyRequest = $this->tests->getBuyRequest($order);

        foreach ($buyRequest['super_attribute'] as $attributeId => $selection)
        {
            $buyRequest['super_attribute'][$attributeId] = "monthly";
        }

        $request->setParam("super_attribute", $buyRequest["super_attribute"]);
        $request->setParam("qty", 2);
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

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check if a partial credit memo was created for the 2nd order
        $order2 = $this->tests->getLastOrder();
        $this->assertEquals(1, $order2->getInvoiceCollection()->getSize());
        $this->assertEquals(1, $order2->getCreditmemosCollection()->getSize());
        $creditmemo = $order2->getCreditmemosCollection()->getFirstItem();

        $totalRefunded = $order->getGrandTotal();

        $this->tests->compare($order2->getData(), [
            "total_invoiced" => $order2->getGrandTotal(),
            "total_paid" => $order2->getGrandTotal(),
            "total_refunded" => $totalRefunded,
            "state" => "complete",
            "status" => "complete"
        ]);

        $this->assertEquals($totalRefunded, $creditmemo->getGrandTotal());

        // Stripe checks
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId])->data;
        usort($charges, [ self::class, "compare" ]);
        $this->assertCount(2, $charges);
        $this->tests->compare($charges, [
            0 => [
                'amount' => 1083,
                'refunded' => false,
                'status' => 'succeeded'
            ],
            1 => [
                'amount' => 1082,
                'refunded' => false,
                'status' => 'succeeded'
            ]
        ]);

        // Downgrade back down to quarterly
        $this->quote->create()->loginOpc()->save();
        $request = $this->subscriptionsController->getRequest();
        $request->setParam("update", $subscription->id);
        $buyRequest = $this->tests->getBuyRequest($order);

        foreach ($buyRequest['super_attribute'] as $attributeId => $selection)
        {
            $buyRequest['super_attribute'][$attributeId] = "quarterly";
        }

        $request->setParam("super_attribute", $buyRequest["super_attribute"]);
        $request->setParam("qty", 1);
        $this->subscriptionsController->execute();

        $this->quote
            ->setBillingAddress("California")
            ->setPaymentMethod("SubscriptionUpdate");

        $billingAddress = $this->quote->getQuote()->getBillingAddress()->getData();
        $shippingAddress = null;
        $shippingMethod = null;
        $this->service->update_subscription($billingAddress, $shippingAddress, $shippingMethod, $couponCode = null);
        $this->tests->runWebhooks();

        // Check that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Stripe checks
        $charges = $this->tests->stripe()->charges->all(['customer' => $customerId])->data;
        usort($charges, [ self::class, "compare" ]);
        $this->assertCount(2, $charges);
        $this->tests->compare($charges, [
            0 => [
                'amount' => 1083,
                'refunded' => false,
                'status' => 'succeeded'
            ],
            1 => [
                'amount' => 1082,
                'refunded' => true,
                'refunds' => [
                    'data' => [
                        0 => [
                            'amount' => 1082
                        ]
                    ]
                ],
                'status' => 'succeeded'
            ]
        ]);
    }

    public function compare($charge1, $charge2)
    {
        if ($charge1->created == $charge2->created)
            return 0;

        return ($charge1->created < $charge2->created) ? -1 : 1;
    }
}
