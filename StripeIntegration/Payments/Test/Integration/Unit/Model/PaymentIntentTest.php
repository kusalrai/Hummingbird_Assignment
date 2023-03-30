<?php

namespace StripeIntegration\Payments\Test\Integration\Unit\Model;

use PHPUnit\Framework\Constraint\StringContains;

/**
 * Magento 2.3.7-p3 does not enable these at class level
 * @magentoAppIsolation enabled
 * @magentoDbIsolation enabled
 */
class PaymentIntentTest extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quote = new \StripeIntegration\Payments\Test\Integration\Helper\Quote();
        $this->paymentIntentModel = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntent::class);
        $this->paymentIntentModelFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentIntentFactory::class);
        $this->paymentElement = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElement::class);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     */
    public function testCacheInvalidation()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California");

        // Create the payment intent
        $quote = $this->quote->getQuote();
        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertNotEmpty($clientSecret);

        // Check if it can be loaded from cache
        $params = $this->paymentIntentModel->getParamsFrom($quote);
        $paymentIntent = $this->paymentIntentModel->loadFromCache($params, $quote, null);
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);
        $this->assertEquals(53.3, $quote->getGrandTotal());
        $this->assertEquals($quote->getGrandTotal() * 100, $paymentIntent->amount);

        // Load attempt 2
        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);

        // Change the cart totals
        $this->quote
            ->setShippingAddress("NewYork")
            ->setShippingMethod("Best")
            ->setBillingAddress("NewYork");

        // Make sure that the payment intent was updated
        $quote = $this->quote->getQuote();
        $params = $this->paymentIntentModel->getParamsFrom($quote);
        $cachedPaymentIntent = $this->paymentIntentModel->loadFromCache($params, $quote, null);
        $this->assertNotEmpty($cachedPaymentIntent->id);
        $this->assertEquals(43.35, $quote->getGrandTotal());
        $this->assertEquals($quote->getGrandTotal() * 100, $cachedPaymentIntent->amount);

        $clientSecret = $this->paymentElement->getClientSecret($quote->getId());
        $this->assertNotEmpty($clientSecret);
        $this->assertEquals($clientSecret, $paymentIntent->client_secret);
    }

    /**
     * @magentoConfigFixture current_store payment/stripe_payments/payment_flow 0
     * In the browser, this can be tested with a Google Pay 3D Secure payment from the product page.
     * Radar needs to be configured to always trigger 3DS for all cards.
     */
    public function testRegulatoryCard()
    {
        $this->quote->create()
            ->setCustomer('Guest')
            ->setCart("Normal")
            ->setShippingAddress("California")
            ->setShippingMethod("FlatRate")
            ->setBillingAddress("California");

        // Check if it can be loaded from cache
        $quote = $this->quote->getQuote();
        $paymentMethod = $this->quote->createPaymentMethodFrom('4242424242424242');
        $params = $this->paymentIntentModel->getParamsFrom($quote, null, $paymentMethod->id);
        $model1 = $this->paymentIntentModelFactory->create();
        $paymentIntent = $model1->create($params, $quote, null);
        $this->assertNotEmpty($paymentIntent);

        // Simulate a client side 3DS confirmation
        $confirmParams = [
            "payment_method" => $paymentMethod->id,
            "return_url" => "http://example.com"
        ];
        $result = $model1->confirm($paymentIntent, $confirmParams);
        $this->assertEquals("succeeded", $result->status);

        // Load attempt 2 after resubmission for server side confirmation
        $model2 = $this->paymentIntentModelFactory->create();
        $paymentIntent2 = $model2->loadFromCache($params, $quote, null);
        $this->assertNotEmpty($paymentIntent2);
        $this->assertEquals($result->id, $paymentIntent2->id);
    }
}
