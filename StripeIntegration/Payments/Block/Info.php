<?php

namespace StripeIntegration\Payments\Block;

use Magento\Framework\Phrase;
use Magento\Payment\Block\ConfigurableInfo;

class Info extends ConfigurableInfo
{
    /**
     * Returns label
     *
     * @param string $field
     * @return Phrase
     */
    protected function getLabel($field)
    {
        return __($field);
    }

    /**
     * @var array
     */
    protected $transactionFields = [
        'bic'=> 'BIC',
        'iban_last4' => 'IBAN Last4'
    ];

    /**
     * Get some specific information in format of array($label => $value)
     *
     * @return array
     */
    public function getSpecificInformation()
    {
        // Get Payment Info
        /** @var \Magento\Payment\Model\Info $_info */
        $_info = $this->getInfo();

        $source_info = $_info->getAdditionalInformation('source_info');
        $source_info = json_decode($source_info, true);

        if ($source_info) {
            $result = [];
            foreach ($source_info as $field => $value)
            {
                if (isset($this->transactionFields[$field]))
                    $description = $this->transactionFields[$field];
                else
                    $description = ucwords(implode(" ", explode('_', $field)));

                $result[$description] = $value;
            }

            return $result;
        }

        return $this->_prepareSpecificInformation()->getData();
    }

    public function cardType($code)
    {
        return $this->helper->cardType($code);
    }

    public function getTitle()
    {
        $info = $this->getInfo();

        if ($info->getAdditionalInformation('is_prapi'))
        {
            $type = $info->getAdditionalInformation("prapi_title");
            if ($type)
                return __("%1 via Stripe", $type);

            return __("Digital Wallet Payment via Stripe");
        }

        return $this->getMethod()->getTitle();
    }
}
