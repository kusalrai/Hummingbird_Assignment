<?php

namespace StripeIntegration\Payments\Controller\Webhooks;

use StripeIntegration\Payments\Helper\Logger;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

class Index extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Framework\View\Result\PageFactory
     */
    protected $resultPageFactory;

    public function __construct(
        \Magento\Framework\App\Action\Context $context,
        \Magento\Framework\View\Result\PageFactory $resultPageFactory,
        \StripeIntegration\Payments\Helper\Webhooks $webhooks
    )
    {
        $this->resultPageFactory = $resultPageFactory;
        parent::__construct($context);

        $this->webhooks = $webhooks;
    }

    /**
     * @return void
     */
    public function execute()
    {
        $this->webhooks->dispatchEvent();
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
