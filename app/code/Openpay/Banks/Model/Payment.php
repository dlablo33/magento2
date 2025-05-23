<?php
/**
 * @category    Payments
 * @package     Openpay_Banks
 * @author      Federico Balderas
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */

namespace Openpay\Banks\Model;

use Magento\Payment\Model\Method\AbstractMethod;
use Magento\Store\Model\ScopeInterface;
use Magento\Customer\Model\Customer;
use Magento\Customer\Model\Session as CustomerSession;

use Openpay\Data\Openpay;
use Magento\Framework\App\Request\Http;
use Openpay\Banks\Model\Utils\Currency;

/**
 * Class Payment
 *
 * @method \Magento\Quote\Api\Data\PaymentMethodExtensionInterface getExtensionAttributes()
 */
class Payment extends AbstractMethod
{

    const CODE = 'openpay_banks';

    protected $_code = self::CODE;
    protected $_isGateway = true;
    protected $_canAuthorize = true;
    protected $_canOrder = true;
    protected $_canCapture = true;
    protected $_canCapturePartial = true;
    protected $_canRefund = false;
    protected $_canRefundInvoicePartial = false;
    protected $_isOffline = true;
    protected $scopeConfig;
    protected $openpay = false;
    protected $is_sandbox;
    protected $country;
    protected $merchant_id = null;
    protected $sk = null;
    protected $deadline = 72;
    protected $sandbox_merchant_id;
    protected $sandbox_sk;
    protected $live_merchant_id;
    protected $live_sk;
    protected $pdf_url_base;
    protected $supported_currency_codes = array('MXN');
    protected $_transportBuilder;
    protected $logger;
    protected $_storeManager;
    protected $_inlineTranslation;
    protected $_directoryList;
    protected $_file;
    protected $iva;
    protected $openpayCustomerFactory;
    protected $request;
    /**
     * @var Currency
     */
    protected $currencyUtils;

    /**
     *
     * @param \Magento\Framework\Model\Context $context
     * @param \Magento\Framework\Registry $registry
     * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
     * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
     * @param \Magento\Payment\Helper\Data $paymentData
     * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
     * @param \Magento\Payment\Model\Method\Logger $logger
     * @param \Openpay\Banks\Mail\Template\TransportBuilder $transportBuilder
     * @param \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     * @param \Psr\Log\LoggerInterface $logger_interface
     * @param \Magento\Framework\App\Filesystem\DirectoryList $directoryList
     * @param \Magento\Framework\Filesystem\Io\File $file
     * @param Customer $customerModel
     * @param CustomerSession $customerSession
     * @param \Openpay\Banks\Model\OpenpayCustomer $openpayCustomerFactory
     * @param array $data
     * @param Currency $currencyUtils
     * @param Http $request
     */
    public function __construct(
        \Magento\Framework\Model\Context $context,
        \Magento\Framework\Registry $registry,
        \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
        \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
        \Magento\Payment\Helper\Data $paymentData,
        \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
        \Magento\Payment\Model\Method\Logger $logger,
        \Openpay\Banks\Mail\Template\TransportBuilder $transportBuilder,
        \Magento\Framework\Translate\Inline\StateInterface $inlineTranslation,
        \Magento\Store\Model\StoreManagerInterface $storeManager,
        \Psr\Log\LoggerInterface $logger_interface,
        \Magento\Framework\App\Filesystem\DirectoryList $directoryList,
        \Magento\Framework\Filesystem\Io\File $file,
        \Magento\Framework\View\Asset\Repository $assetRepository,
            Customer $customerModel,
            CustomerSession $customerSession,
            \Openpay\Banks\Model\OpenpayCustomer $openpayCustomerFactory,
            Currency $currencyUtils,
            Http $request,
        array $data = []
    ) {
        parent::__construct(
            $context,
            $registry,
            $extensionFactory,
            $customAttributeFactory,
            $paymentData,
            $scopeConfig,
            $logger,
            null,
            null,
            $data
        );

        $this->request = $request;
        $this->customerModel = $customerModel;
        $this->customerSession = $customerSession;
        $this->openpayCustomerFactory = $openpayCustomerFactory;
        $this->assetRepository = $assetRepository;

        $this->_file = $file;
        $this->_directoryList = $directoryList;
        $this->logger = $logger_interface;
        $this->_inlineTranslation = $inlineTranslation;
        $this->_storeManager = $storeManager;
        $this->_transportBuilder = $transportBuilder;
        $this->scopeConfig = $scopeConfig;

        $this->is_active = $this->getConfigData('active');
        $this->is_sandbox = $this->getConfigData('is_sandbox');
        $this->country = $this->getConfigData('country');

        $this->currencyUtils = $currencyUtils;

        $this->sandbox_merchant_id = $this->getConfigData('sandbox_merchant_id');
        $this->sandbox_sk = $this->getConfigData('sandbox_sk');
        $this->live_merchant_id = $this->getConfigData('live_merchant_id');
        $this->live_sk = $this->getConfigData('live_sk');
        $this->deadline = $this->country === 'MX' ? $this->getConfigData('deadline_hours') : null;
        $this->iva = $this->country === 'CO' ? $this->getConfigData('iva') : 0;

        $this->merchant_id = $this->is_sandbox ? $this->sandbox_merchant_id : $this->live_merchant_id;
        $this->sk = $this->is_sandbox ? $this->sandbox_sk : $this->live_sk;
        $this->pdf_url_base = $this->is_sandbox ? 'https://sandbox-dashboard.openpay.mx/spei-pdf' : 'https://dashboard.openpay.mx/spei-pdf';
    }

    /**
     *
     * @param \Magento\Payment\Model\InfoInterface $payment
     * @param float $amount
     * @return \Openpay\Banks\Model\Payment
     * @throws \Magento\Framework\Validator\Exception
     */
    public function order(\Magento\Payment\Model\InfoInterface $payment, $amount) {
        unset($_SESSION['openpay_pse_redirect_url']);

        /**
         * Magento utiliza el timezone UTC, por lo tanto sobreescribimos este
         * por la configuración que se define en el administrador
         */
        $store_tz = $this->scopeConfig->getValue('general/locale/timezone');
        date_default_timezone_set($store_tz);

        /** @var \Magento\Sales\Model\Order $order */
        $order = $payment->getOrder();

        /** @var \Magento\Sales\Model\Order\Address $billing */
        $billing = $order->getBillingAddress();

        $origin_channel = "PLUGIN_MAGENTO";

        $this->logger->debug('#order', array('$order_id' => $order->getIncrementId(), '$status' => $order->getStatus(), '$amount' => $amount));

        try {

            $customer_data = array(
                'requires_account' => false,
                'name' => $billing->getFirstname(),
                'last_name' => $billing->getLastname(),
                'phone_number' => $billing->getTelephone(),
                'email' => $order->getCustomerEmail()
            );

            if ($this->validateAddress($billing)) {
                $customer_data = $this->formatAddress($customer_data, $billing);
            }

            $this->logger->debug('#order', array('$customer_data' => $customer_data));


            if ($this->country === 'MX') {
                $due_date = date('Y-m-d\TH:i:s', strtotime('+ '.$this->deadline.' hours'));

                $charge_request = array(
                    'method' => 'bank_account',
                    'amount' => $amount,
                    'currency' => strtolower($order->getBaseCurrencyCode()),
                    'description' => sprintf('ORDER #%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),
                    'order_id' => $order->getIncrementId(),
                    'due_date' => $due_date,
                    'customer' => $customer_data,
                    'origin_channel' => $origin_channel
                );
            } elseif($this->country === 'CO') {
                $base_url = $this->_storeManager->getStore()->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);  // URL de la tienda

                $charge_request = array(
                    'method' => 'bank_account',
                    'amount' => $amount,
                    'currency' => strtolower($order->getBaseCurrencyCode()),
                    'description' => sprintf('ORDER #%s, %s', $order->getIncrementId(), $order->getCustomerEmail()),
                    'order_id' => $order->getIncrementId(),
                    'customer' => $customer_data,
                    'iva' => $this->iva,
                    'origin_channel' => $origin_channel,
                    'redirect_url' => $base_url.'openpay/pse/confirm'
                );
            }

            $this->logger->debug('#order', array('$charge_request' => $charge_request));

            // Realiza la transacción en Openpay
            $charge = $this->makeOpenpayCharge($customer_data, $charge_request);
            $charge_id = '';

            if ($charge->id) {
                $charge_id = $charge->id;
            } elseif(!$charge->id && $charge->redirect_url) {
                //$url_array = explode('/', $charge->redirect_url);
                //$charge_id = $url_array[8];
            }

            $payment->setTransactionId($charge_id);

            $openpayCustomerFactory = $this->customerSession->isLoggedIn() ? $this->hasOpenpayAccount($this->customerSession->getCustomer()->getId()) : null;
            $openpay_customer_id = $openpayCustomerFactory ? $openpayCustomerFactory->openpay_id : null;

            // Actualiza el estado de la orden
            $state = \Magento\Sales\Model\Order::STATE_NEW;
            $order->setState($state)->setStatus($state);

            // Registra el ID de la transacción de Openpay
            $order->setExtOrderId($charge_id);
            // Registra (si existe), el ID de Customer de Openpay
            $order->setExtCustomerId($openpay_customer_id);
            $order->save();

            if ($this->country === 'MX') {
                $pdf_url = $this->pdf_url_base.'/'.$this->merchant_id.'/'.$charge_id;
                $_SESSION['pdf_url'] = $pdf_url;

                $pdf_file = $this->handlePdf($pdf_url, $order->getIncrementId());
                $this->sendEmail($pdf_file, $order);
            } elseif ($this->country === 'CO' && $charge->payment_method->type == 'redirect') {
                $_SESSION['openpay_pse_redirect_url'] = $charge->payment_method->url;
            }

        } catch (\Exception $e) {
            $this->debugData($e->getMessage());
            $this->_logger->error(__( $e->getMessage()));
            throw new \Magento\Framework\Validator\Exception(__($this->error($e)));
        }

        $payment->setSkipOrderProcessing(true);
        return $this;
    }

    private function makeOpenpayCharge($customer_data, $charge_request) {
        $openpay = $this->getOpenpayInstance();

        // Cargo para usuarios "invitados"
        if (!$this->customerSession->isLoggedIn()) {
            return $openpay->charges->create($charge_request);
        }

        // Se remueve el atributo de "customer" porque ya esta relacionado con una cuenta en Openpay
        unset($charge_request['customer']);

        $openpay_customer = $this->retrieveOpenpayCustomerAccount($customer_data);

        try {
            // Cargo para usuarios con cuenta
            $this->logger->debug('#makeOpenpayCharge', array('bank_account' => true));
            return $openpay_customer->charges->create($charge_request);

        } catch (\Exception $e) {
            $this->logger->critical('#makeOpenpayCharge', array('error' => $e->getMessage()));
            $this->logger->critical('#makeOpenpayCharge', array('getTraceAsString' => $e->getTraceAsString()));
            return false;
        }
    }

    private function retrieveOpenpayCustomerAccount($customer_data) {
        try {
            $customerId = $this->customerSession->getCustomer()->getId();

            $has_openpay_account = $this->hasOpenpayAccount($customerId);
            if ($has_openpay_account === false) {
                $openpay_customer = $this->createOpenpayCustomer($customer_data);
                $this->logger->debug('$openpay_customer => '.$openpay_customer->id);

                $data = [
                    'customer_id' => $customerId,
                    'openpay_id' => $openpay_customer->id
                ];

                // Se guarda en BD la relación
                $this->openpayCustomerFactory->addData($data)->save();
            } else {
                $openpay_customer = $this->getOpenpayCustomer($has_openpay_account->openpay_id);
                if($openpay_customer === false){
                    $openpay_customer = $this->createOpenpayCustomer($customer_data);
                    $this->logger->debug('#update openpay_customer', array('$openpay_customer_old' => $has_openpay_account->openpay_id, '$openpay_customer_old_new' => $openpay_customer->id));

                    // Se actualiza en BD la relación
                    $openpay_customer_local_update = $this->openpayCustomerFactory->load($has_openpay_account->openpay_customer_id);
                    $openpay_customer_local_update->setOpenpayId($openpay_customer->id);
                    $openpay_customer_local_update->save();
                }
            }

            return $openpay_customer;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }

    private function createOpenpayCustomer($data) {
        try {
            $openpay = $this->getOpenpayInstance();
            return $openpay->customers->add($data);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }

    private function hasOpenpayAccount($customer_id) {
        try {
            $response = $this->openpayCustomerFactory->fetchOneBy('customer_id', $customer_id);
            return $response;
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }

    public function getOpenpayCustomer($openpay_customer_id) {
        try {
            $openpay = $this->getOpenpayInstance();
            $customer = $openpay->customers->get($openpay_customer_id);
            if(isset($customer->balance)){
                return false;
            }
            return $customer;
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getOpenpayCharge($charge_id, $customer_id = null) {
        try {
            if ($customer_id === null) {
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }

            $openpay_customer = $this->getOpenpayCustomer($customer_id);

            if($openpay_customer === false){
                $openpay = $this->getOpenpayInstance();
                return $openpay->charges->get($charge_id);
            }

            return $openpay_customer->charges->get($charge_id);
        } catch (\Exception $e) {
            throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
    }

    private function formatAddress($customer_data, $billing) {
        if ($this->country === 'MX') {
            $customer_data['address'] = array(
                'line1' => $billing->getStreetLine(1),
                'line2' => $billing->getStreetLine(2),
                'postal_code' => $billing->getPostcode(),
                'city' => $billing->getCity(),
                'state' => $billing->getRegion(),
                'country_code' => $billing->getCountryId()
            );
        } else if ($this->country === 'CO') {
            $customer_data['customer_address'] = array(
                'department' => $billing->getRegion(),
                'city' => $billing->getCity(),
                'additional' => $billing->getStreetLine(1).' '.$billing->getStreetLine(2)
            );
        }

        return $customer_data;
    }

    public function sendEmail($pdf_file, $order) {
        $templateId = 'openpay_spei_pdf_template';
        $email = $this->scopeConfig->getValue('trans_email/ident_support/email',ScopeInterface::SCOPE_STORE);
        $name  = $this->scopeConfig->getValue('trans_email/ident_support/name',ScopeInterface::SCOPE_STORE);
        $pdf = file_get_contents($pdf_file);
        $toEmail = $order->getCustomerEmail();

        try {
            $template_vars = array(
                'title' => 'Instrucciones de pago | Orden #'.$order->getIncrementId()
            );
            $storeId = $this->_storeManager->getStore()->getId();
            $from = array('email' => $email, 'name' => $name);

            $templateOptions = array(
                'area' => \Magento\Framework\App\Area::AREA_FRONTEND,
                'store' => $storeId
            );

            $this->logger->debug('#sendEmail', array('$pdf_path' => $pdf_file, '$from' => $from, '$toEmail' => $toEmail));

            $transportBuilderObj = $this->_transportBuilder
            ->setTemplateIdentifier($templateId)
            ->setTemplateOptions($templateOptions)
            ->setTemplateVars($template_vars)
            ->setFrom($from)
            ->addTo($toEmail)
            ->addAttachment($pdf, 'instrucciones_pago.pdf', 'application/octet-stream')
            ->getTransport();
            $transportBuilderObj->sendMessage();

            return;
        } catch (\Magento\Framework\Exception\MailException $me) {
            $this->logger->error('#MailException', array('msg' => $me->getMessage()));
        } catch (\Exception $e) {
            $this->logger->error('#Exception', array('msg' => $e->getMessage()));
        }
    }

    private function handlePdf($url, $order_id) {
        $pdfContent = file_get_contents($url);
        $filePath = "/openpay/attachments/";
        $pdfPath = $this->_directoryList->getPath('media') . $filePath;
        $ioAdapter = $this->_file;

        if (!is_dir($pdfPath)) {
            $ioAdapter->mkdir($pdfPath, 0775);
        }

        $fileName = "payment_receipt_".$order_id.".pdf";
        $ioAdapter->open(array('path' => $pdfPath));
        $ioAdapter->write($fileName, $pdfContent, 0666);

        return $pdfPath.$fileName;
    }

    public function validateSettings() {
        $website_id = (int) $this->request->getParam('website', 0);
        $is_active = $this->scopeConfig->getValue("payment/openpay_banks/active",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $this->logger->debug( '#payment.validateSettings', array( 'plugin_is_active' => $is_active ) );
        if($is_active){
            $current_country = $this->scopeConfig->getValue("payment/openpay_banks/country",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
            $supportedCurrencies = $this->currencyUtils->getSupportedCurrenciesByCountryCode($current_country);
            if (!$this->currencyUtils->isSupportedCurrentCurrency($supportedCurrencies)) {
                $currenciesAsString = implode(', ', $supportedCurrencies);
                throw new \Magento\Framework\Validator\Exception(__('The '. $this->currencyUtils->getCurrentCurrency() .' currency is not suported, the supported currencies are: ' . $currenciesAsString));
            }
        }
    }

    /**
     * @param Exception $e
     * @return string
     */
    public function error($e) {

        /* 6001 el webhook ya existe */
        switch ($e->getCode()) {
            case '1000':
            case '1004':
            case '1005':
                $msg = 'Servicio no disponible.';
                break;
            case '6001':
                $msg = 'El webhook ya existe, has caso omiso de este mensaje.';
            case '6002':
                $msg = 'El webhook no pudo ser verificado, revisa la URL.';
            default: /* Demás errores 400 */
                $msg = 'La petición no pudo ser procesada.';
                break;
        }

        return 'ERROR '.$e->getCode().'. '.$msg;
    }

    /**
     * @param Address $billing
     * @return boolean
     */
    public function validateAddress($billing) {
        if ($billing->getCountryId() === 'MX' && $billing->getStreetLine(1) && $billing->getCity() && $billing->getPostcode() && $billing->getRegion()) {
            return true;
        }else if ($billing->getCountryId() === 'CO' && $billing->getStreetLine(1) && $billing->getCity() && $billing->getRegion()) {
            return true;
        }
        return false;
    }

    /**
     * Create webhook
     * @return mixed
     */
    public function createWebhook() {
        $website_id = (int) $this->request->getParam('website', 0);
        $is_active = $this->scopeConfig->getValue("payment/openpay_banks/active",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        if($is_active){
            $base_url = $this->_storeManager->getStore($website_id)->getBaseUrl(\Magento\Framework\UrlInterface::URL_TYPE_WEB);
            $openpay = $this->getCurrentSiteOpenpayInstance();
            $uri = $base_url."openpay/index/webhook";
            $webhooks = $openpay->webhooks->getList([]);
            $webhookCreated = $this->isWebhookCreated($webhooks, $uri);
        }else{
            $webhookCreated = (object) [
                "url" => ""
            ];
        }

        if ($webhookCreated) {
            return $webhookCreated;
        }

        $webhook_data = array(
            'url' => $uri,
            'event_types' => array(
                'verification',
                'charge.succeeded',
                'charge.created',
                'charge.cancelled',
                'charge.failed',
                'payout.created',
                'payout.succeeded',
                'payout.failed',
                'spei.received',
                'chargeback.created',
                'chargeback.rejected',
                'chargeback.accepted',
                'transaction.expired'
            )
        );

        try {
            $webhook = $openpay->webhooks->add($webhook_data);
            return $webhook;
        } catch (Exception $e) {
            return $this->error($e);
        }
    }



    public function getCountry() {
        return $this->country;
    }

    public function getImagePath()
    {
        $fileId = 'Openpay_Banks::images/logo_pse.png';
        $params = [
            'area' => 'frontend'
        ];
        $asset = $this->assetRepository->createAsset($fileId, $params);
        try {
            return $asset->getUrl();
        } catch (\Exception $e) {
            return null;
        }
    }

    public function getCurrentSiteOpenpayInstance(){
        $website_id = (int) $this->request->getParam('website', 0);
        $current_is_sandbox = $this->scopeConfig->getValue("payment/openpay_banks/is_sandbox",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $current_sandbox_merchant_id = $this->scopeConfig->getValue("payment/openpay_banks/sandbox_merchant_id",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $current_live_merchant_id = $this->scopeConfig->getValue("payment/openpay_banks/live_merchant_id",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $current_sandbox_sk = $this->scopeConfig->getValue("payment/openpay_banks/sandbox_sk",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $current_live_sk = $this->scopeConfig->getValue("payment/openpay_banks/live_sk",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        $current_country = $this->scopeConfig->getValue("payment/openpay_banks/country",\Magento\Store\Model\ScopeInterface::SCOPE_STORE,$website_id );
        
        $current_merchant_id = $current_is_sandbox ? $current_sandbox_merchant_id : $current_live_merchant_id;
        $current_sk = $current_is_sandbox ? $current_sandbox_sk : $current_live_sk;
        
        $openpay = $this->getOpenpayInstance($current_merchant_id,$current_sk,$current_country,$current_is_sandbox);
        return $openpay;
    }
        
    public function getOpenpayInstance($merchant_id = null, $sk = null, $country = null, $is_sandbox = null) {
        $ipClient = $this->getIpClient();

        if(is_null($merchant_id)){
            $merchant_id = $this->merchant_id;
        }

        if(is_null($sk)){
            $sk = $this->sk;
        }

        if(is_null($country)){
            $country = $this->country;
        }
        
        if(is_null($is_sandbox)){
            $is_sandbox = $this->is_sandbox;
        }

        $openpay = Openpay::getInstance($merchant_id,$sk,$country,$ipClient);
        Openpay::setSandboxMode($is_sandbox);
        $userAgent = "Openpay-MTO2".$country."/v2";
        Openpay::setUserAgent($userAgent);

        return $openpay;
    }

    private function isWebhookCreated($webhooks, $uri) {
        foreach ($webhooks as $webhook) {
            if ($webhook->url === $uri) {
                return $webhook;
            }
        }
        return null;
    }

    /**
     * Get Ip of client
     */
    public function getIpClient(){
        // Recogemos la IP de la cabecera de la conexión
        if (!empty($_SERVER['HTTP_CLIENT_IP']))
        {
            $ipAdress = $_SERVER['HTTP_CLIENT_IP'];
            $this->logger->debug('#HTTP_CLIENT_IP', array('$IP' => $ipAdress));
        }
        // Caso en que la IP llega a través de un Proxy
        elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR']))
        {
            $ipAdress = $_SERVER['HTTP_X_FORWARDED_FOR'];
            $this->logger->debug('#HTTP_X_FORWARDED_FOR', array('$IP' => $ipAdress));
        }
        // Caso en que la IP lleva a través de la cabecera de conexión remota
        else
        {
            $ipAdress = $_SERVER['REMOTE_ADDR'];
            $this->logger->debug('#REMOTE_ADDR', array('$IP' => $ipAdress));
        }
        $ipAdress = explode(",", $ipAdress)[0];
        return $ipAdress;
    }

}
