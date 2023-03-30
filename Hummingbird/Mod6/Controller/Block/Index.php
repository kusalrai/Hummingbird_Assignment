<?php

namespace Hummingbird\Mod6\Controller\Block;

use \Magento\Framework\Controller\ResultFactory;

class Index extends \Magento\Framework\App\Action\Action
{
    protected $pageFactory;
	protected $resultFactory;
    
	public function __construct(
		\Magento\Framework\App\Action\Context $context,
		\Magento\Framework\View\Result\PageFactory $pageFactory,
		 ResultFactory $resultFactory)
	{
		$this->pageFactory = $pageFactory;
		$this->resultFactory = $resultFactory;
	    parent::__construct($context);
	}

	public function execute()
	{
        $layout = $this->pageFactory->create()->getLayout();
        $block = $layout->createBlock('Hummingbird\Mod6\Block\CustomBlock');
        $result = $this->resultFactory->create(ResultFactory::TYPE_RAW);
        $result->setContents($block->toHtml());
        return $result;
	}
}
