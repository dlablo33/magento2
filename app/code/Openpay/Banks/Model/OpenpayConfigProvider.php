<?php
/**
 * Copyright © 2015 Pay.nl All rights reserved.
 */

namespace Openpay\Banks\Model;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Openpay\Banks\Model\Payment as OpenpayPayment;
use Magento\Checkout\Model\Cart;

class OpenpayConfigProvider implements ConfigProviderInterface
{
    /**
     * @var string[]
     */
    protected $methodCodes = [
        'openpay_banks',        
    ];

    /**
     * @var \Magento\Payment\Model\Method\AbstractMethod[]
     */
    protected $methods = [];
    
    /**
     * @var \Openpay\Banks\Model\Payment
     */
    protected $payment ;

    protected $cart;


    /**     
     * @param PaymentHelper $paymentHelper
     * @param OpenpayPayment $payment
     */
    public function __construct(PaymentHelper $paymentHelper, OpenpayPayment $payment, Cart $cart) {        
        foreach ($this->methodCodes as $code) {
            $this->methods[$code] = $paymentHelper->getMethodInstance($code);
        }
        $this->cart = $cart;
        $this->payment = $payment;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfig()
    {                
        $config = [];
        foreach ($this->methodCodes as $code) {
            if ($this->methods[$code]->isAvailable()) {
                $config['openpay_banks']['country'] = $this->payment->getCountry();
                $config['openpay_banks']['image_pse'] = $this->payment->getImagePath();
            }
        }
                
        return $config;
    }       
    
}
