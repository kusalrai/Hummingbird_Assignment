<?php

namespace StripeIntegration\Payments\Test\Integration\Frontend\CheckoutPage\EmbeddedFlow\AuthorizeCapture\MixedTrial;

use PHPUnit\Framework\Constraint\StringContains;

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
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * @magentoConfigFixture current_store payment/stripe_payments/payment_action authorize_capture
     */
    public function testPlaceOrder()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("MixedTrial")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California")
            ->setPaymentMethod("SuccessCard");

        $order = $this->quote->placeOrder();

        $ordersCount = $this->tests->getOrdersCount();

        $paymentIntent = $this->tests->confirmSubscription($order);

        $stripe = $this->tests->stripe();

        $customerId = $order->getPayment()->getAdditionalInformation("customer_stripe_id");
        $customer = $stripe->customers->retrieve($customerId);
        $this->assertEquals(1, count($customer->subscriptions->data));
        $subscription = $customer->subscriptions->data[0];
        $this->assertNotEmpty($subscription->latest_invoice);
        $invoiceId = $subscription->latest_invoice;

        // Process the subscription's invoice.payment_succeeded event
        $invoice = $stripe->invoices->retrieve($invoiceId, ['expand' => ['charge']]);
        $this->assertNotEmpty($invoice->subscription);
        $subscriptionId = $invoice->subscription;

        // The one time payment is $15.83
        $this->tests->compare($invoice, [
            "total" => 1583,
            "amount_due" => 1583,
            "amount_paid" => 1583,
            "amount_remaining" => 0
        ]);

        // Process the regular products charge.succeeded event
        $paymentIntentId = $paymentIntent->id;
        $this->assertStringContainsString($order->getPayment()->getLastTransId(), $paymentIntentId);

        // Ensure that no new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount, $newOrdersCount);

        // Refresh the order object
        $order = $this->tests->refreshOrder($order);
        $this->assertEquals("processing", $order->getState());
        $this->assertEquals("processing", $order->getStatus());

        // Check that an invoice was created
        $invoicesCollection = $order->getInvoiceCollection();
        $this->assertNotEmpty($invoicesCollection);
        $this->assertEquals(1, $invoicesCollection->getSize());

        $invoice = $invoicesCollection->getFirstItem();
        $this->assertCount(2, $invoice->getAllItems());
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
        $this->assertEquals($paymentIntentId, $invoice->getTransactionId());
        $this->tests->compare($order->getData(), [
            "total_paid" => $order->getGrandTotal(),
            "base_total_paid" => $order->getBaseGrandTotal(),
        ]);

        // Check that the transaction IDs have been associated with the order
        $transactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));
        foreach ($transactions as $key => $transaction)
        {
            $this->assertEquals($paymentIntentId, $transaction->getTxnId());
            $this->assertContains($transaction->getTxnType(), ["capture", "refund"]);
            $this->assertEquals(15.83, $transaction->getAdditionalInformation("amount"));
        }

        // End the trial
        $ordersCount = $this->tests->getOrdersCount();
        $subscription = $this->tests->endTrialSubscription($subscriptionId);

        // Ensure that a new order was created
        $newOrdersCount = $this->tests->getOrdersCount();
        $this->assertEquals($ordersCount + 1, $newOrdersCount);

        // Check that the transaction IDs has NOT been associated with the old order
        $transactions = $this->tests->helper()->getOrderTransactions($order);
        $this->assertEquals(1, count($transactions));

        // Check the newly created order
        $newOrder = $this->tests->getLastOrder();
        $this->assertNotEquals($order->getIncrementId(), $newOrder->getIncrementId());
        $this->assertEquals("processing", $newOrder->getState());
        $this->assertEquals("processing", $newOrder->getStatus());
        $this->assertEquals(15.83, $newOrder->getGrandTotal());
        $this->assertEquals(15.83, $newOrder->getTotalPaid());
        $this->assertEquals(1, $newOrder->getInvoiceCollection()->getSize());
        $invoice = $newOrder->getInvoiceCollection()->getFirstItem();
        $this->assertEquals(\Magento\Sales\Model\Order\Invoice::STATE_PAID, $invoice->getState());
    }
}
