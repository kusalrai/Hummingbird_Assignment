<?php

namespace Hummingbird\Mod8\Block;

use Hummingbird\Mod8\Model\ResourceModel\Employee\Collection;
use Magento\Framework\View\Element\Template\Context;
use Magento\Framework\View\Element\Template;

class Table extends Template
{

    private $collection;

 
    public function __construct(
        Context $context,
        Collection $collection,
        array $data = []
    ) {
        $this->collection = $collection;
        parent::__construct($context, $data);
    }


    public function getAllEmployees()
    {
        return $this->collection;
    }

    public function getPostUrl()
    {
        return $this->getUrl('mod8/employee/save');
    }

}