<?php

namespace Hummingbird\Mod1\Controller\Action;

class Customrouter implements \Magento\Framework\App\ActionInterface
{
   protected $resultFactory;
   protected $test;
    
   public function __construct(
       \Magento\Framework\Controller\ResultFactory $resultFactory,
       \Hummingbird\Mod1\Humage\Test $test)
   {
       $this->resultFactory = $resultFactory;
       $this->test = $test;
   }

   public function execute()
   {
       $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
       $result->setContents($this->test->displayParams());
       return $result;
   }
}
