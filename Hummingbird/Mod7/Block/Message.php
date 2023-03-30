<?php

namespace Hummingbird\Mod7\Block;

class Message extends \Magento\Framework\View\Element\Template
{
    public function __construct(\Magento\Framework\View\Element\Template\Context $context)
    {
        parent::__construct($context);
    }

    public function getMessage()
    {
        return __('Before Price Message');
    }

    public function _afterToHtml($html)
    {
        return parent::_afterToHtml($html . "<h6> Additional message from after to html method</h6>");
    }
}