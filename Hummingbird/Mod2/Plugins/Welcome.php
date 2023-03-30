<?php
namespace Hummingbird\Mod2\Plugins;

class Welcome
{
    public function afterGetWelcome(\Magento\Theme\Block\Html\Header $subject, $result){
        return 'Hey there welcome!!';
    }
}
