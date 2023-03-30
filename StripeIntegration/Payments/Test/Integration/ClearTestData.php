<?php

namespace StripeIntegration\Payments\Test\Integration;

class ClearTestData extends \PHPUnit\Framework\TestCase
{
    public function setUp(): void
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->tests = new \StripeIntegration\Payments\Test\Integration\Helper\Tests($this);
    }

    /**
     * @magentoDbIsolation enabled
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeys.php
     */
    public function testClearTestData()
    {
        $this->clear();
    }

    /**
     * @magentoDbIsolation enabled
     * @magentoDataFixture ../../../../app/code/StripeIntegration/Payments/Test/Integration/_files/Data/ApiKeysUK.php
     */
    public function testClearTestDataUK()
    {
        $this->clear();
    }

    protected function clear()
    {
        $this->tests->config()->reInitStripeFromStoreCode("default");
        $subscriptions = $this->tests->stripe()->subscriptions->all(['limit' => 100]);

        foreach ($subscriptions->autoPagingIterator() as $subscription)
        {
            if ($subscription->status == "trialing" || $subscription->status == "active")
                $this->tests->stripe()->subscriptions->cancel($subscription->id, []);
        }

        $endpoints = $this->tests->stripe()->webhookEndpoints->all(['limit' => 100]);
        foreach ($endpoints->autoPagingIterator() as $endpoint)
        {
            $endpoint->delete();
        }
    }
}
