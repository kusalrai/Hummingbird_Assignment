<?php

namespace Hummingbird\Mod8\Setup;

use \Magento\Framework\Setup\InstallSchemaInterface;
use \Magento\Framework\Setup\ModuleContextInterface;
use \Magento\Framework\Setup\SchemaSetupInterface;
use \Magento\Framework\DB\Ddl\Table;

class InstallSchema implements InstallSchemaInterface
{

    public function install(SchemaSetupInterface $setup, ModuleContextInterface $context)
    {
        $setup->startSetup();

        $tableName = $setup->getTable('employee_table');

        if ($setup->getConnection()->isTableExists($tableName) != true) {
            $table = $setup->getConnection()
                ->newTable($tableName)
                ->addColumn(
                    'employee_id',
                    Table::TYPE_INTEGER,
                    null,
                    [
                        'identity' => true,
                        'unsigned' => true,
                        'nullable' => false,
                        'primary' => true
                    ],
                    'ID'
                )
                ->addColumn(
                    'first_name',
                    Table::TYPE_TEXT,
                    255,
                    ['nullable' => false],
                    'First Name'
                )
                ->addColumn(
                    'last_name',
                    Table::TYPE_TEXT,
                    null,
                    ['nullable' => false],
                    'Last Name'
                )
                ->addColumn(
                    'email_id',
                    Table::TYPE_TEXT,
                    null,
                    ['nullable' => false],
                    'Email Id'
                )
                ->setComment('Employee - Table');
            $setup->getConnection()->createTable($table);
        }
         $setup->endSetup();
    }
}