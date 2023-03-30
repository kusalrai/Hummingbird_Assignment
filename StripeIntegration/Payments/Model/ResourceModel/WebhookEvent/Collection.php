<?php

namespace StripeIntegration\Payments\Model\ResourceModel\WebhookEvent;

class Collection extends \Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection
{
    protected $_idFieldName = 'id';

    protected function _construct()
    {
        $this->_init('StripeIntegration\Payments\Model\WebhookEvent', 'StripeIntegration\Payments\Model\ResourceModel\WebhookEvent');
    }

    public function getFailedEvents($maxRetries = 6, $minAgeMinutes = 5)
    {
        $minimumAge = date('Y-m-d H:i:s', time() - $minAgeMinutes * 60);

        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('created_at', ['lt' => $minimumAge])
            ->addFieldToFilter('is_processed', ['eq' => false])
            ->addFieldToFilter('retries', ['lt' => $maxRetries]);

        return $collection;
    }

    public function getAllFailedEventsOfType($type)
    {
        $collection = $this
            ->addFieldToSelect('*')
            ->addFieldToFilter('event_type', ['eq' => $type])
            ->addFieldToFilter('is_processed', ['eq' => false]);

        return $collection;
    }
}
