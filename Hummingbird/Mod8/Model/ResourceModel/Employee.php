<?php

namespace Hummingbird\Mod8\Model\ResourceModel;


use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

class Employee extends AbstractDb
{
    const MAIN_TABLE = 'employee_table';
    const ID_FIELD_NAME = 'employee_id';

    protected function _construct()
    {
        $this->_init(self::MAIN_TABLE, self::ID_FIELD_NAME);
    }
}