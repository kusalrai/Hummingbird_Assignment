<?php

namespace StripeIntegration\Payments\Test\Integration\Helper;

class Tests
{
    protected $objectManager = null;
    protected $quoteRepository = null;
    protected $productRepository = null;
    protected $tests = null;
    protected $lastWebhookEvent = null;
    protected $processedEvents = [];

    public function __construct($test)
    {
        $this->objectManager = \Magento\TestFramework\ObjectManager::getInstance();
        $this->quoteRepository = $this->objectManager->create(\Magento\Quote\Api\CartRepositoryInterface::class);
        $this->productRepository = $this->objectManager->get(\Magento\Catalog\Api\ProductRepositoryInterface::class);
        $this->checkoutSession = $this->objectManager->get(\Magento\Checkout\Model\Session::class);
        $this->cartManagement = $this->objectManager->get(\Magento\Quote\Api\CartManagementInterface::class);
        $this->orderFactory = $this->objectManager->get(\Magento\Sales\Model\OrderFactory::class);
        $this->quoteManagement = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\QuoteManagement::class);
        $this->store = $this->objectManager->get(\Magento\Store\Model\StoreManagerInterface::class)->getStore();
        $this->invoiceRepository = $this->objectManager->get(\Magento\Sales\Api\InvoiceRepositoryInterface::class);
        $this->creditmemoItemInterfaceFactory = $this->objectManager->get(\Magento\Sales\Api\Data\CreditmemoItemCreationInterfaceFactory::class);
        $this->refundOrder = $this->objectManager->get(\Magento\Sales\Api\RefundOrderInterface::class);
        $this->orderRepository = $this->objectManager->get(\Magento\Sales\Api\OrderRepositoryInterface::class);
        $this->creditmemoFactory = $this->objectManager->get(\Magento\Sales\Model\Order\CreditmemoFactory::class);
        $this->creditmemoService = $this->objectManager->get(\Magento\Sales\Model\Service\CreditmemoService::class);
        $this->stripeConfig = $this->objectManager->get(\StripeIntegration\Payments\Model\Config::class);
        $this->helper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Generic::class);
        $this->address = $this->objectManager->get(\StripeIntegration\Payments\Test\Integration\Helper\Address::class);
        $this->checkoutSessionsCollectionFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\ResourceModel\CheckoutSession\CollectionFactory::class);
        $this->event = new \StripeIntegration\Payments\Test\Integration\Helper\Event($test);
        $this->checkoutHelper = new \StripeIntegration\Payments\Test\Integration\Helper\Checkout($test);
        $this->compare = new \StripeIntegration\Payments\Test\Integration\Helper\Compare($test);
        $this->test = $test;
        $this->invoiceService = $this->objectManager->get(\Magento\Sales\Model\Service\InvoiceService::class);
        $this->paymentElementFactory = $this->objectManager->get(\StripeIntegration\Payments\Model\PaymentElementFactory::class);
        $this->shipOrder = $this->objectManager->get(\Magento\Sales\Api\ShipOrderInterface::class);
        $this->productMetadata = $this->objectManager->get(\Magento\Framework\App\ProductMetadataInterface::class);
        $this->paymentControllerFactory = $this->objectManager->get(\StripeIntegration\Payments\Controller\Payment\IndexFactory::class);
        $this->dataHelper = $this->objectManager->get(\StripeIntegration\Payments\Helper\Data::class);
    }

    public function refundOffline($invoice, $itemSkus)
    {
        $items = [];

        foreach ($invoice->getAllItems() as $invoiceItem)
        {
            if ($invoiceItem->getOrderItem()->getParentItem())
                continue;

            $sku = $invoiceItem->getSku();

            if(in_array($sku, $itemSkus))
            {
                $creditmemoItem = $this->creditmemoItemInterfaceFactory->create();
                $items[] = $creditmemoItem
                            ->setQty($invoiceItem->getQty())
                            ->setOrderItemId($invoiceItem->getOrderItemId());
            }
        }

        // Create the credit memo
        $this->refundOrder->execute($invoice->getOrderId(), $items, true, false);
    }

    public function refundOnline($invoice, $itemQtys, $baseShippingAmount = 0, $adjustmentPositive = 0, $adjustmentNegative = 0)
    {
        if (empty($invoice) || !$invoice->getId())
            throw new \Exception("Invalid invoice");

        $qtys = [];

        foreach ($invoice->getAllItems() as $invoiceItem)
        {
            if ($invoiceItem->getOrderItem()->getParentItem())
                continue;

            $sku = $invoiceItem->getSku();

            if(isset($itemQtys[$sku]))
                $qtys[$invoiceItem->getOrderItem()->getId()] = $itemQtys[$sku];
        }

        if (count($itemQtys) != count($qtys))
            throw new \Exception("Specified SKU not found in invoice items.");

        $params = [
            "qtys" => $qtys,
            "shipping_amount" => $baseShippingAmount,
            "adjustment_positive" => $adjustmentPositive,
            "adjustment_negative" => $adjustmentNegative
        ];

        if (empty($invoice->getTransactionId()))
            throw new \Exception("Cannot refund online because the invoice has no transaction ID");

        $creditmemo = $this->creditmemoFactory->createByInvoice($invoice, $params);

        // Create the credit memo
        return $this->creditmemoService->refund($creditmemo);
    }

    public function invoiceOnline($order, $itemQtys, $captureCase = \Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE)
    {
        $orderItemIDs = [];
        $orderItemQtys = [];

        foreach ($order->getAllVisibleItems() as $orderItem)
        {
            $orderItemIDs[$orderItem->getSku()] = $orderItem->getId();
        }

        foreach ($itemQtys as $sku => $qty)
        {
            if (isset($orderItemIDs[$sku]))
            {
                $id = $orderItemIDs[$sku];
                $orderItemQtys[$id] = $qty;
            }
        }

        $invoice = $this->invoiceService->prepareInvoice($order, $orderItemQtys);
        $invoice->setRequestedCaptureCase($captureCase);
        $order->setIsInProcess(true);
        $invoice->register();
        $invoice->pay();
        $this->helper->saveOrder($order);
        return $this->helper->saveInvoice($invoice);
    }

    public function stripe()
    {
        return $this->stripeConfig->getStripeClient();
    }

    public function config()
    {
        return $this->stripeConfig;
    }

    public function event()
    {
        return $this->event;
    }

    public function saveProduct($product)
    {
        return $this->productRepository->save($product);
    }

    public function getProduct($sku)
    {
        return $this->productRepository->get($sku);
    }

    public function getOrdersCount()
    {
        return $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->count();
    }

    public function getLastOrder()
    {
        return $this->objectManager->get('Magento\Sales\Model\Order')->getCollection()->setOrder('increment_id','DESC')->getFirstItem();
    }

    public function getOrderBySortPosition($sortPosition)
    {
        $orders = $this->objectManager->create('Magento\Sales\Model\Order')->getCollection()->setOrder('increment_id','DESC');

        foreach ($orders as $order)
        {
            if ($sortPosition == 1)
                return $order;

            $sortPosition--;
        }

        return null;
    }

    public function getLastCheckoutSession()
    {
        $collection = $this->checkoutSessionsCollectionFactory->create()
            ->addFieldToSelect('*')
            ->setOrder('created_at','DESC');

        $model = $collection->getFirstItem();

        if ($model->getCheckoutSessionId())
            return $this->stripe()->checkout->sessions->retrieve($model->getCheckoutSessionId(), ['expand' => ['payment_intent', 'subscription']]);

        throw new \Exception("There are no Stripe Checkout sessions cached.");
    }

    public function getStripeCustomer()
    {
        $customerModel = $this->helper->getCustomerModel();
        if ($customerModel->getStripeId())
            return $this->stripe()->customers->retrieve($customerModel->getStripeId());

        return null;
    }

    public function checkout()
    {
        return $this->checkoutHelper;
    }

    public function compare($object, array $expectedValues)
    {
        return $this->compare->object($object, $expectedValues);
    }

    public function helper()
    {
        return $this->helper->clearCache();
    }

    public function refreshOrder($order)
    {
        if (!$order->getId())
            throw new \Exception("No order ID provided");

        return $this->orderFactory->create()->load($order->getId());
    }

    public function address()
    {
        return $this->address;
    }

    public function assertCheckoutSessionsCountEquals($count)
    {
        $sessions = $this->checkoutSessionsCollectionFactory->create()->addFieldToSelect('*');
        $this->test->assertEquals($count, $sessions->getSize());
        $session = $sessions->getFirstItem();
        $this->test->assertStringContainsString("cs_test_", $session->getCheckoutSessionId());
    }

    public function endTrialSubscription($subscriptionId)
    {
        // End the trial
        $this->stripe()->subscriptions->update($subscriptionId, ['trial_end' => "now"]);
        $subscription = $this->stripe()->subscriptions->retrieve($subscriptionId, ['expand' => ['latest_invoice']]);

        // Trigger webhook events for the trial end
        $this->event()->trigger("charge.succeeded", $subscription->latest_invoice->charge);
        $this->event()->trigger("invoice.payment_succeeded", $subscription->latest_invoice->id, ['billing_reason' => 'subscription_cycle']);
        return $subscription;
    }

    public function confirm($order, $params = [])
    {
        $paymentElement = $this->paymentElementFactory->create();

        $params["return_url"] = "https://integration-tests.loc";

        $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
        if ($paymentMethodId)
            $params["payment_method"] = $paymentMethodId;

        $clientSecret = $paymentElement->getClientSecret($order->getQuoteId(), $paymentMethodId, $order);
        $paymentIntent = $this->stripe()->paymentIntents->confirm($paymentElement->getPaymentIntentId(), $params);
        $controller = $this->paymentControllerFactory->create();
        $controller->getRequest()->setParam('payment_intent', $paymentIntent->id);
        $controller->execute();
        $this->event()->triggerPaymentIntentEvents($paymentIntent);

        return $paymentIntent;
    }

    public function confirmSubscription($order, $triggerEvents = true)
    {
        $paymentElement = $this->paymentElementFactory->create();

        $params["return_url"] = "https://integration-tests.loc";

        $paymentMethodId = $order->getPayment()->getAdditionalInformation("token");
        if ($paymentMethodId)
            $params["payment_method"] = $paymentMethodId;

        $clientSecret = $paymentElement->getClientSecret($order->getQuoteId(), $paymentMethodId, $order);
        $this->test->assertNotEmpty($clientSecret, "The client secret could not be created");

        if (strpos($clientSecret, "seti_") === 0)
        {
            $obj = $setupIntent = $this->stripe()->setupIntents->confirm($paymentElement->getSetupIntentId(), $params);

            if ($triggerEvents)
            {
                $this->event()->trigger("setup_intent.succeeded", $paymentElement->getSetupIntentId());
            }
        }
        else
        {
            $obj = $paymentIntent = $this->stripe()->paymentIntents->confirm($paymentElement->getPaymentIntentId(), $params);

            if ($triggerEvents)
            {
                $subscription = $paymentElement->getSubscription();
                $this->event()->triggerSubscriptionEvents($subscription);
            }
        }

        return $obj;
    }

    public function confirmCheckoutSession($order, $cart, $paymentMethod = "card", $address = "California")
    {
        // Confirm the payment
        $session = $this->checkout()->retrieveSession($order, $cart);
        $response = $this->checkout()->confirm($session, $order, $paymentMethod, $address);

        if (!empty($response->payment_intent))
        {
            $this->checkout()->authenticate($response->payment_intent, $paymentMethod);
            $paymentIntent = $this->stripe()->paymentIntents->retrieve($response->payment_intent->id);

            // Trigger webhooks
            $customer = $this->stripe()->customers->retrieve($response->customer->id);
            if (!empty($customer->subscriptions->data))
            {
                foreach ($customer->subscriptions->data as $subscription)
                {
                    $this->event()->triggerSubscriptionEvents($subscription);
                }

                return $paymentIntent;
            }
            else if ($response->payment_intent)
            {
                $this->event()->triggerPaymentIntentEvents($response->payment_intent->id);

                return $paymentIntent;
            }
            else /* if ($response->setup_intent) */
                throw new \Exception("Setup intent not implemented.");
        }
        else
            throw new \Exception("Setup intent not implemented.");
    }

    public function shipOrder($orderId)
    {
        $this->shipOrder->execute($orderId);
    }

    public function reInitConfig()
    {
        $this->objectManager->get(\Magento\Framework\App\Config\ReinitableConfigInterface::class)->reinit();
        $this->objectManager->create(\Magento\Store\Model\StoreManagerInterface::class)->reinitStores();
    }

    public function magento($operator, $version)
    {
        $magentoVersion = $this->productMetadata->getVersion();
        return version_compare($magentoVersion, $version, $operator);
    }

    public function startWebhooks()
    {
        if (!empty($this->lastWebhookEvent))
            return;

        $name = rand();

        \StripeIntegration\Payments\Helper\Logger::log("Starting webhooks.");
        $this->stripe()->products->create(['name' => $name]);
        $events = $this->stripe()->events->all(['limit' => 100, 'created' => ['gt' => time() - 60], 'types' => ['product.created']]);

        $event = null;
        foreach ($events->autoPagingIterator() as $eventData)
        {
            if ($eventData->data->object->name == $name)
            {
                $event = $eventData;
            }
        }

        if (empty($event))
            throw new \Exception("Could not start webhooks");

        $this->processedEvents[$event->id] = true;
        $this->lastWebhookEvent = $event;
    }

    public function runWebhooks($wait = 1)
    {
        sleep($wait); // Wait for Stripe to generate all events
        $types = \StripeIntegration\Payments\Helper\WebhooksSetup::$enabledEvents;
        $events = $this->stripe()->events->all(['limit' => 100, 'created' => ["gte" => $this->lastWebhookEvent->created]]);
        $sortedEvents = [];

        foreach ($events->autoPagingIterator() as $event)
        {
            if (in_array($event->type, $types) && !isset($this->processedEvents[$event->id]))
            {
                $sortedEvents[] = $event;
                $this->processedEvents[$event->id] = true;

                if ($event->created > $this->lastWebhookEvent->created)
                {
                    $this->lastWebhookEvent = $event;
                }
            }
        }

        // Trigger the events in the reverse order that Stripe listed them
        for ($i = count($sortedEvents) - 1; $i >= 0; $i--)
        {
            $this->event()->dispatchEvent($sortedEvents[$i]);
        }
    }

    public function orderHasComment($order, string $text)
    {
        $statuses = $order->getAllStatusHistory();

        foreach ($statuses as $status)
        {
            $comment = $status['comment'];
            if (strpos($comment, $text) !== false)
            {
                return true;
            }
        }

        return false;
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
