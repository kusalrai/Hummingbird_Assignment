<?php
namespace Hummingbird\Mod6\Block\Product\View;

class Description extends \Magento\Framework\View\Element\Template
{
    public function beforeToHtml(\Magento\Catalog\Block\Product\View\Description $description)
    {
        $description->getProduct()->setDescription('sample description by kusal');                                                                                                                                
    }
}
