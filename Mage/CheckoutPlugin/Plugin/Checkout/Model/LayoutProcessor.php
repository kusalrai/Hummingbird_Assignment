<?php

namespace Mage\CheckoutPlugin\Plugin\Checkout\Model;

use Magento\Checkout\Block\Checkout\LayoutProcessor as CheckoutLayoutProcessor;

class LayoutProcessor
{

    public function afterProcess(CheckoutLayoutProcessor $subject, array $jsLayout)
    {


    $customAttributeCode = 'addresstype_field';
    $customField = [

    'component' => 'Magento_Ui/js/form/element/select',
    'config' => [


        'customScope' => 'shippingAddress.custom_attributes',
        'customEntry' => null,
        'template' => 'ui/form/field',
        'elementTmpl' => 'ui/form/element/select',
        'tooltip' => [
            'description' => 'Select the address type, Commercial addresses will be charged higher',
        ],
    ],

    'dataScope' => 'shippingAddress.custom_attributes' . '.' . $customAttributeCode,

    'label' => 'Address Type',
    'provider' => 'checkoutProvider',
    'sortOrder' => 0,
    'validation' => [
       'required-entry' => true
    ],

    'options' => [
        [
            'value'=>'res',
            'label'=>'Residential'
        ],
        [
            'value'=>'com',
            'label'=>'Commercial'
        ]
    ],

    'filterBy' => null,
    'customEntry' => null,
    'visible' => true,
    'value' => 'res'

];


        $jsLayout['components']['checkout']['children']['steps']['children']['shipping-step']['children']['shippingAddress']['children']['shipping-address-fieldset']['children'][$customAttributeCode] = $customField;

        return $jsLayout;
    }
}
