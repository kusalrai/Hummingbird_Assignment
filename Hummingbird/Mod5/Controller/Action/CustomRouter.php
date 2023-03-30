<?php

namespace Hummingbird\Mod5\Controller\Action;

class CustomRouter implements \Magento\Framework\App\ActionInterface
{
	private $resultFactory;

	public function __construct(\Magento\Framework\Controller\ResultFactory $resultFactory)
	{
		$this->resultFactory = $resultFactory;
	}

	public function execute()
	{
		$result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_REDIRECT);
		// $result->setContents('Hello World!');
		$result->setPath("beaumont-summit-kit.html");
		return $result;	
	}
}
			