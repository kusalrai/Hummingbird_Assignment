<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\RedirectFlow\AuthorizeCapture\DynamicBundleMixedTrial;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PlaceOrderTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();

        $this->subscriptions = $this->objectManager->get(\StripeIntegration\Payments\Helper\Subscriptions::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 1
     */
    public function testDynamicBundleMixedTrialCart()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("DynamicBundleMixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("StripeCheckout");

        $quote = $this->quote->getQuote();

        // Checkout totals should be correct
        $trialSubscriptionsConfig = $this->subscriptions->getTrialingSubscriptionsAmounts($quote);

        // 4 subscriptions x $10 and 50% off special price
        $this->assertEquals(20, $trialSubscriptionsConfig["subscriptions_total"], "Subtotal");
        $this->assertEquals(20, $trialSubscriptionsConfig["base_subscriptions_total"], "Base Subtotal");

        // 4 subscriptions x $5 and 50% off special price
        $this->assertEquals(10, $trialSubscriptionsConfig["shipping_total"], "Shipping");
        $this->assertEquals(10, $trialSubscriptionsConfig["base_shipping_total"], "Base Shipping");

        $this->assertEquals(0, $trialSubscriptionsConfig["discount_total"], "Discount");
        $this->assertEquals(0, $trialSubscriptionsConfig["base_discount_total"], "Base Discount");

        $this->assertEquals(1.65, $trialSubscriptionsConfig["tax_total"], "Tax");
        $this->assertEquals(1.65, $trialSubscriptionsConfig["tax_total"], "Base Tax");

        // Place the order
        $order = $this->quote->placeOrder();

        $ordersCount = $this->tests->getOrdersCount();

        // Assert order status, amount due, invoices
        $this->assertEquals("new", $order->getState());
        $this->assertEquals("pending", $order->getStatus());
        $this->assertEquals(0, $order->getInvoiceCollection()->count());

        $paymentIntent = $this->tests->confirmCheckoutSession($order, "DynamicBundleMixedTrial", "card", "California");

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        $orderIncrementId = $order->getIncrementId();
        $currency = $order->getOrderCurrencyCode();
        $expectedChargeAmount = $order->getGrandTotal()
            - $trialSubscriptionsConfig["subscriptions_total"]
            - $trialSubscriptionsConfig["shipping_total"]
            + $trialSubscriptionsConfig["discount_total"]
            - $trialSubscriptionsConfig["tax_total"];

        $expectedChargeAmountStripe = $this->tests->helper()->convertMagentoAmountToStripeAmount($expectedChargeAmount, $currency);

        // Retrieve the created session
        $checkoutSessionId = $order->getPayment()->getAdditionalInformation('checkout_session_id');
        $this->assertNotEmpty($checkoutSessionId);

        $stripe = $this->tests->stripe();
        $session = $stripe->checkout->sessions->retrieve($checkoutSessionId);

        $this->assertEquals($expectedChargeAmountStripe, $session->amount_total);

        // Refresh the order
        $order = $this->tests->refreshOrder($order);

        // Stripe subscription checks
        $customer = $stripe->customers->retrieve($session->customer);
        $this->assertCount(1, $customer->subscriptions->data);
        $subscription = $customer->subscriptions->data[0];
        $this->assertEquals("trialing", $subscription->status);

        // 4 subscriptions * $5 + shipping 4 * $2.50 + tax $1.65 = $31.65
        $this->assertEquals(3165, $subscription->items->data[0]->price->unit_amount);

        $subscriptionId = $subscription->id;

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);

        // Assert order status, amount due, invoices, invoice items, invoice totals
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals($order->getGrandTotal(), $order->getTotalPaid());
        $this->assertEquals(1, $order->getInvoiceCollection()->getSize());
        $invoice = $order->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());

        // End the trial
        $this->tests->endTrialSubscription($subscriptionId);
        $order = $this->tests->refreshOrder($order);

        // Check that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check that the subscription transaction IDs have NOT been associated with the old order
        $transactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            if ($transaction->getTxnType() == "capture")
            {
                $this->assertEquals($paymentIntent->id, $transaction->getTxnId());
                $this->assertEquals(53.3, $transaction->getAdditionalInformation("amount"));
            }
            else if ($transaction->getTxnType() == "refund")
            {
                $this->markTestIncomplete("Why is there a refund of 53.3 here?");
            }
        }

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(31.65, $newOrder->getGrandTotal()); // @todo: Magento bug - amount should be the same as the initial order
        $this->assertEquals(10, $newOrder->getShippingAmount()); // @todo: Magento bug - shipping should be the same as the initial order
        $this->assertEquals($newOrder->getGrandTotal(), $newOrder->getTotalPaid());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());

        // Process a recurring subscription billing webhook
        $subscription = $this->tests->stripe()->subscriptions->retrieve($subscriptionId, []);
        $this->tests->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice, ['billing_reason' => 'subscription_cycle']);

        // Get the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(0, $order->getTotalDue());
        $this->assertEquals(1, $order->getInvoiceCollection()->count());

        // Credit memos check
        $this->assertEquals(1, $order->getCreditmemosCollection()->getSize());
        $creditmemo = $order->getCreditmemosCollection()->getFirstItem();
        $this->assertEquals($order->getGrandTotal() - $expectedChargeAmount, $creditmemo->getGrandTotal());
    }
}
