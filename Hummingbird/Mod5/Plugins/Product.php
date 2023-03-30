<?php
namespace Hummingbird\Mod5\Plugins;

class Product
{
    public function afterGetName(\Magento\Catalog\Model\Product $product, $result)
    {
        return $result .' '. 'Woww';
    }
}
