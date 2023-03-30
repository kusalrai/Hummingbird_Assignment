<?php
/**
 * Pyxl_SmartyStreets
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Open Software License (OSL 3.0)
 * that is bundled with this package in the file LICENSE.txt.
 * It is also available through the world-wide-web at this URL:
 * http://opensource.org/licenses/osl-3.0.php
 *
 * @copyright  Copyright (c) 2018 Pyxl, Inc.
 * @license    http://opensource.org/licenses/osl-3.0.php Open Software License (OSL 3.0)
 */

namespace Pyxl\SmartyStreets\Model;

use SmartyStreets\PhpSdk\Exceptions\SmartyException;
use SmartyStreets\PhpSdk\StaticCredentials;
use SmartyStreets\PhpSdk\ClientBuilder;
use SmartyStreets\PhpSdk\US_Street\Lookup;

class Validator
{
    /** 
     * Address for validation
     * Street Add
     * 1500 E Main St
     * Newark OH 43055-8847
     * 
     * Country
     * US
     * 
     * State
     * Ohio
     * 
     * City 
     * Newark
     * 
     * 43055    
     * 
     * 
     * 
     * 
     * */ 

    //region Properties

    /**
     * @var \Pyxl\SmartyStreets\Helper\Config
     */
    private $config;

    /**
     * @var \Psr\Log\LoggerInterface
     */
    private $logger;

    //endregion

    /**
     * Validator constructor.
     *
     * @param \Pyxl\SmartyStreets\Helper\Config $config
     * @param \Psr\Log\LoggerInterface $logger
     */
    public function __construct(
        \Pyxl\SmartyStreets\Helper\Config $config,
        \Psr\Log\LoggerInterface $logger
    )
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    /**
     * Validates the given address using SmartyStreets API.
     * If valid returns all candidates
     * If not valid returns appropriate messaging
     *
     * @param \Magento\Customer\Api\Data\AddressInterface $address
     * @return array
     */
    public function validate(\Magento\Customer\Api\Data\AddressInterface $address) 
    {
        $response = [
            'valid' => false,
            'candidates' => []
        ];



        $staticCredentials = new StaticCredentials(
            $this->config->getAuthId(),
            $this->config->getAuthToken()
        );
        $client = (new ClientBuilder($staticCredentials))->withLicenses(["us-core-cloud"])->buildUsStreetApiClient();

        
        $street = $address->getStreet();
        $lookup = new Lookup();
            
        if ($street && !empty($street)) {
            $lookup->setStreet($street[0]);
            $lookup->setSecondary((count($street)>1) ? $street[1] : null);
        }
        if ($region = $address->getRegion()) {
            $lookup->setState($region->getRegionCode());
        }
        $lookup->setCity($address->getCity());
        $lookup->setZipcode($address->getPostcode());
        $lookup->setMaxCandidates(3);
        

        try {
            $client->sendLookup($lookup);
            /** @var \SmartyStreets\PhpSdk\US_Street\Candidate[]|\SmartyStreets\PhpSdk\International_Street\Candidate[] $result */
            $result = $lookup->getResult();
            // if no results it means address is not valid.
            if (empty($result)) {
                $response['message'] = __(
                    'Invalid Address, Please try again with a valid address!'
                );
            } else {
                $response['valid'] = true;
                $response['candidates'] = $result;
            }
        } catch (SmartyException $e) {
            // Received error back from API.
            $response['message'] = __($e->getMessage());
        } catch (\Exception $e) {
            $response['message'] = __(
                'There was an unknown error. Please try again later.'
            );
            $this->logger->error($e);
        }
        return $response;
    }

}