<?php

namespace Hummingbird\Mod5\Controller\Adminhtml\Action;

class Index extends \Magento\Backend\App\Action
{
    protected $resultFactory;

    public function __construct(
        \Magento\Backend\App\Action\Context $context,
        \Magento\Framework\Controller\ResultFactory $resultFactory
        )
	{
		$this->resultFactory = $resultFactory;
        return parent::__construct($context);
	}

    public function execute()
    {
        $result = $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_RAW);
        $result->setContents('Hello Admin Controller!');
        return $result;
    }

    protected function _isAllowed()
    {
        $secret = $this->getRequest()->getParam('access');
        return isset($secret) && (int)$secret==1;
    }

    public function _processUrlKeys()
    {
        return true;
    }
}
