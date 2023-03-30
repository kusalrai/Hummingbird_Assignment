<?php
namespace Hummingbird\Mod4\Controller;

use Magento\Framework\App\RouterInterface;
use Magento\Framework\App\ActionFactory;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\App\RequestInterface as AppRequestInterface;

class Router implements RouterInterface{

    protected $actionFactory;
    protected $_response;

    public function __construct(ActionFactory $actionFactory, ResponseInterface $response){

        $this->actionFactory = $actionFactory;
        $this->_response = $response;
    }

    public function match(AppRequestInterface $request){
        $pathInfo = $request->getPathInfo();
        $regex = '/[A-Z][a-z]*/';

        if(preg_match_all($regex, $pathInfo, $matches)==3){
            $parsedUrl = sprintf("/%s/%s/%s",
                strtolower($matches[0][0])
                ,strtolower($matches[0][1])
                ,strtolower($matches[0][2]));

            $this->_response->setRedirect($parsedUrl);
            return $this->actionFactory->create('Magento\Framework\App\Action\Redirect');
        }

        return null;
    }
}

