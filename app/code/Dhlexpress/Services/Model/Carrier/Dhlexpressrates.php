<?php
namespace Dhlexpress\Services\Model\Carrier;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Shipping\Model\Carrier\AbstractCarrier;
use Magento\Shipping\Model\Carrier\CarrierInterface;
use Magento\Shipping\Model\Config;
use Magento\Shipping\Model\Rate\ResultFactory;
use Magento\Store\Model\ScopeInterface;
use Magento\Quote\Model\Quote\Address\RateResult\ErrorFactory;
use Magento\Quote\Model\Quote\Address\RateResult\Method;
use Magento\Quote\Model\Quote\Address\RateResult\MethodFactory;
use Magento\Quote\Model\Quote\Address\RateRequest;
use Psr\Log\LoggerInterface;

class Dhlexpressrates extends AbstractCarrier implements CarrierInterface
{
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        ErrorFactory $rateErrorFactory,
        LoggerInterface $logger,
        ResultFactory $rateResultFactory,
        MethodFactory $rateMethodFactory,
        \Magento\Framework\HTTP\Client\Curl $curl,
        array $data = []
    ) {
        $this->_code = 'dhlrates';
        $this->_rateResultFactory = $rateResultFactory;
        $this->_rateMethodFactory = $rateMethodFactory;
        $this->_logger = $logger;
        $this->_curl = $curl;
        parent::__construct($scopeConfig, $rateErrorFactory, $logger, $data);
    }

    public function getAllowedMethods()
    {
        return [$this->getCarrierCode() => __($this->getConfigData('name'))];
    }

    public function collectRates(RateRequest $request)
    {
        if (!$this->isActive()) {
            return false;
        }

        $result = $this->_rateResultFactory->create();

        $apiKey = $this->getConfigData('apikey');
        $apiSource = $this->getConfigData('apisource');

        if ($apiKey == "") {
            return false;
        }

        if ($apiSource == "") {
            $apiSource = "StarShipIT";
        }

        try {
            $destCountryId = $request->getDestCountryId();
            $destCountry = $request->getDestCountry();
            $destRegion = $request->getDestRegionId();
            $destRegionCode = $request->getDestRegionCode();
            $destFullStreet = $request->getDestStreet();
            $destStreet = "";
            $destSuburb = "";
            $destCity = $request->getDestCity();
            $destPostcode = $request->getDestPostcode();
            if ($destFullStreet != null && $destFullStreet != "") {
                $destFullStreetArray = explode("\n", $destFullStreet);
                $count = count($destFullStreetArray);
                if ($count > 0 && $destFullStreetArray[0] !== false) {
                    $destStreet = $destFullStreetArray[0];
                }
                if ($count > 1 && $destFullStreetArray[1] !== false) {
                    $destSuburb = $destFullStreetArray[1];
                }
            }

            $url = 'https://api.starshipit.com/api/rates/shopify?apiKey=';
            $url = $url . $apiKey . '&integration_type=magento&source=DHL';

            $itemsJson = '';

            try {
                // new way of getting the rates per item

                $objectManager = \Magento\Framework\App\ObjectManager::getInstance();

                if (is_null($objectManager)) {
                    $errorMessage = "ObjectManager was not found";
                    throw new \Exception($e->$errorMessage);
                }

                foreach ($request->getAllItems() as $item){
                    $productId = $item->getProductId();
                    $product = $objectManager->create('Magento\Catalog\Model\Product')->load($productId);

                    if (is_null($product)) {
                        $errorMessage = "Product with Id ".$product." not found";
                        throw new \Exception($e->$errorMessage);
                    }

                    $height = $product->getData('height');
                    if (!is_numeric($height)){
                        $height = 0;
                    }
                    $length = $product->getData('length');
                    if (!is_numeric($length)){
                        $length = 0;
                    }
                    $width = $product->getData('width');
                    if (!is_numeric($width)){
                        $width = 0;
                    }
                    $name = str_replace('"', '\\"', $product->getName());
                    $sku = str_replace('"', '\\"', $item->getSku());
                    $weight = $product->getWeight();
                    $price = $product->getPrice();
                    $quantity = $item->getQty();

                    if(!empty($itemsJson)){
                        $itemsJson = $itemsJson . ',';
                    }

                    $itemsJson = $itemsJson . '{
                        "name": "' . $name . '",
                        "sku": "' . $sku . '",
                        "quantity": '. $quantity . ',
                        "grams": ' . $weight * 1000 . ' ,
                        "price": ' . $price . ',
                        "vendor": null,
                        "requires_shipping": true,
                        "taxable": true,
                        "fulfillment_service": "manual",
                        "height": ' . $height . ',
                        "length": ' . $length . ',
                        "width": ' . $width . '
                    }';
                }
            } catch (\Exception $e) {
                // old way of getting the rates summing up the item velues to one big item

                $packageValue = $request->getPackageValue();
                $packageWeight = $request->getPackageWeight() * 1000;

                $itemsJson = '{
                            "name": "Total Items",
                            "sku": null,
                            "quantity": 1,
                            "grams": ' . $packageWeight . ' ,
                            "price": ' . $packageValue . ',
                            "vendor": null,
                            "requires_shipping": true,
                            "taxable": true,
                            "fulfillment_service": "manual"
                          }';

                $this->_logger->debug("Starshipit Rates Exception - using old code");
                $this->_logger->debug($e);
            }

            $post_data = '{
                        "rate": {
                            "destination":{
                            "country": "' . $destCountryId . '",
                            "postal_code": "' . $destPostcode . '",
                            "province": "' . $destRegionCode . '",
                            "city": "' . $destCity . '",
                            "name": null,
                            "address1": "' . $destStreet . '",
                            "address2": "' . $destSuburb . '",
                            "address3": null,
                            "phone": null,
                            "fax": null,
                            "address_type": null,
                            "company_name": null
                            },
                            "items":['. $itemsJson .']
                        }
                    }';

            $options = [ CURLOPT_HTTPHEADER => ['Content-Type: application/json'] ];
            $this->_curl->setOptions($options);
            $this->_curl->post($url, $post_data);
            $response = $this->_curl->getBody();

            $json_obj = json_decode($response);
            $rates_obj = $json_obj->{'rates'};
            $rates_count = count($rates_obj);
            if ($rates_count > 0) {
                foreach ($rates_obj as $rate) {
                    if (is_object($rate)) {
                        // Add shipping option with shipping price
                        $method = $this->_rateMethodFactory->create();
                        $method->setCarrier($this->getCarrierCode());
                        $method->setCarrierTitle($this->getConfigData('title'));
                        $method->setMethod($rate->{'service_code'});
                        $method->setMethodTitle($rate->{'service_name'});
                        $method->setPrice($rate->{'total_price'});
                        $method->setCost(0);
                        $result->append($method);
                    }
                }
            }
        } catch (\Exception $e) {
            $this->_logger->debug("DHL Express Rates Exception");
            $this->_logger->debug($e);
        }

        return $result;
    }
}
