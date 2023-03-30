<?php

namespace Hummingbird\Mod8\Model;

use Magento\Framework\Model\AbstractModel;

class Employee extends AbstractModel
{
    protected function _construct()
    {
        $this->_init(ResourceModel\Employee::class);
    }
}
