<?php

namespace Hummingbird\Mod4\Test\App;

use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\ValidatorInterface as RequestValidator;
use Magento\Framework\App\ObjectManager;
use Magento\Framework\App\RouterListInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Message\MessageInterface as MessageManager;
use Psr\Log\LoggerInterface;

class FrontController extends \Magento\Framework\App\FrontController
{
    private $logger;

    public function __construct(
        RouterListInterface $routerList,
        ResponseInterface $response,
        ?RequestValidator $requestValidator = null,
        ?MessageManager $messageManager = null,
        ?LoggerInterface $logger = null
    )
    {
        $this->logger = $logger ?? ObjectManager::getInstance()->get(LoggerInterface::class);
        parent::__construct($routerList, $response, $requestValidator, $messageManager, $logger);      
    }

    public function dispatch(RequestInterface $request)
    {
        $routerList = [];
        foreach ($this->_routerList as $router) {
        $routerList[] = $router;
        }
        $routerList = array_map(function ($item) {
        return get_class($item);
        }, $routerList);
        $routerList = "\n\r" . implode("\n\r", $routerList);
        $this->logger->info("List of all routers:" . $routerList);       
        return parent::dispatch($request);
    }
}
