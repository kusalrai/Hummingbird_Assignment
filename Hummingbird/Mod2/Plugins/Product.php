<?php
namespace Hummingbird\Mod2\Plugins;

class Product 
{
    public function afterGetName(\Magento\Catalog\Model\Product $product,$result)
    {
        $price = $product->getPrice();
        if($price < 60){
            $result = $result.' on sale';
        }
        
        return $result;
    }
}
