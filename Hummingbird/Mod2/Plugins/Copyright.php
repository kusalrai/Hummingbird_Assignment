<?php
namespace Hummingbird\Mod2\Plugins;

class Copyright
{
    public function afterGetCopyright(\Magento\Theme\Block\Html\Footer $subject, $result)
    {
        return 'Footer by Kusal';
    }
}

