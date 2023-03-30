<?php

namespace Hummingbird\Mod3\Observer\Product;

use Magento\Framework\Event\Observer;

class Data implements \Magento\Framework\Event\ObserverInterface
{

    private $logger;
    public function __construct(\Psr\Log\LoggerInterface $logger){
        $this->logger = $logger;
    }

    public function execute(Observer $observer)
    {
        $data = $observer->getData('product');
        $this->logger->info($data->getName());
    }
}