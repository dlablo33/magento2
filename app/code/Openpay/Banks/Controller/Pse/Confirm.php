<?php
/** 
 * @category    Payments
 * @package     Openpay_Banks
 * @author      Federico Balderas
 * @copyright   Openpay (http://openpay.mx)
 * @license     http://www.apache.org/licenses/LICENSE-2.0  Apache License Version 2.0
 */
namespace Openpay\Banks\Controller\Pse;

use Magento\Framework\App\Action\Context;
use Magento\Framework\View\Result\PageFactory;
use Openpay\Banks\Model\Payment as OpenpayPayment;
use Magento\Sales\Model\ResourceModel\Order\Invoice\Collection as InvoiceCollection;
use Magento\Sales\Model\Order\Invoice;


class Confirm extends \Magento\Framework\App\Action\Action
{
    protected $resultPageFactory;
    protected $request;
    protected $payment;
    protected $checkoutSession;
    protected $orderRepository;
    protected $logger;
    protected $_invoiceService;
    protected $transactionBuilder;
    
    /**
     * 
     * @param Context $context
     * @param PageFactory $resultPageFactory
     * @param \Magento\Framework\App\Request\Http $request
     * @param OpenpayPayment $payment
     * @param \Magento\Sales\Api\OrderRepositoryInterface $orderRepository
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Psr\Log\LoggerInterface $logger_interface
     * @param \Magento\Sales\Model\Service\InvoiceService $invoiceService
     */
    public function __construct(
            Context $context, 
            PageFactory $resultPageFactory, 
            \Magento\Framework\App\Request\Http $request, 
            OpenpayPayment $payment,
            \Magento\Sales\Api\OrderRepositoryInterface $orderRepository,
            \Magento\Checkout\Model\Session $checkoutSession,
            \Psr\Log\LoggerInterface $logger_interface,
            \Magento\Sales\Model\Service\InvoiceService $invoiceService,
            \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder
    ) {
        parent::__construct($context);
        $this->resultPageFactory = $resultPageFactory;
        $this->request = $request;
        $this->payment = $payment;
        $this->checkoutSession = $checkoutSession;
        $this->orderRepository = $orderRepository;
        $this->logger = $logger_interface;        
        $this->_invoiceService = $invoiceService;
        $this->transactionBuilder = $transactionBuilder;
    }
    /**
     * Load the page defined in view/frontend/layout/openpay_pse_confirm.xml
     * URL /openpay/pse/confirm
     * 
     * @url https://magento.stackexchange.com/questions/197310/magento-2-redirect-to-final-checkout-page-checkout-success-failed?rq=1
     * @return \Magento\Framework\View\Result\Page
     */
    public function execute() {                
        try {                        
            $order_id = $this->checkoutSession->getLastOrderId();
            $quote_id = $this->checkoutSession->getLastQuoteId();
            
            $this->checkoutSession->setLastSuccessQuoteId($quote_id);
            
            $this->logger->debug('getLastQuoteId: '.$quote_id);
            $this->logger->debug('getLastOrderId: '.$order_id);
            $this->logger->debug('getLastSuccessQuoteId: '.$this->checkoutSession->getLastSuccessQuoteId());
            $this->logger->debug('getLastRealOrderId: '.$this->checkoutSession->getLastRealOrderId());        
            
            $openpay = $this->payment->getOpenpayInstance();                          
            $order = $this->orderRepository->get($order_id);        
            $customer_id = $order->getExtCustomerId();
            
            if ($customer_id) {
                $customer = $this->payment->getOpenpayCustomer($customer_id);
                $charge = $customer->charges->get($this->request->getParam('id'));
            } else {
                $charge = $openpay->charges->get($this->request->getParam('id'));
            }
            
            $this->logger->debug('#PSE', array('id' => $this->request->getParam('id'), 'status' => $charge->status));

            if($order){
                if ($charge->status == 'completed') {
                    $status = \Magento\Sales\Model\Order::STATE_PROCESSING;            
                    $order->setExtOrderId($this->request->getParam('id')); // Registra el ID de la transacción de Openpay
                    $order->setState($status)->setStatus($status);
                    $order->setTotalPaid($charge->amount);  
                    $order->addStatusHistoryComment("Pago recibido exitosamente")->setIsCustomerNotified(true);            
                    $order->save();        
                    $requiresInvoice = true;
                    /** @var InvoiceCollection $invoiceCollection */
                    $invoiceCollection = $order->getInvoiceCollection();
                    if ( $invoiceCollection->count() > 0 ) {
                        /** @var Invoice $invoice */
                        foreach ($invoiceCollection as $invoice ) {
                            if ( $invoice->getState() == Invoice::STATE_OPEN) {
                                $invoice->setState(Invoice::STATE_PAID);
                                $invoice->setTransactionId($charge->id);
                                $invoice->pay()->save();
                                $requiresInvoice = false;
                                break;
                            }
                        }
                    }
                    if ( $requiresInvoice ) {
                        $invoice = $this->_invoiceService->prepareInvoice($order);
                        $invoice->setTransactionId($charge->id);
                        // $invoice->setRequestedCaptureCase(\Magento\Sales\Model\Order\Invoice::CAPTURE_ONLINE);
                        // $invoice->register();
                        $invoice->pay()->save();
                    }
                    $payment = $order->getPayment();                                
                    $payment->setAmountPaid($charge->amount);
                    $payment->setIsTransactionPending(false);
                    $payment->save();      
                }else if($charge->status == 'cancelled' || $charge->status == 'failed'){
                    $order->cancel();
                    $order->addStatusToHistory(\Magento\Sales\Model\Order::STATE_CANCELED, __('Pago vía PSE fallido'));
                    $order->save();
                    $this->logger->debug('#PSE', array('msg' => 'Pago vía PSE fallido'));
                                    
                    return $this->resultPageFactory->create();   
                }
            }
            
            $this->logger->debug('#PSE', array('redirect' => 'checkout/onepage/success'));
            return $this->resultRedirectFactory->create()->setPath('checkout/onepage/success');
            
        } catch (\Exception $e) {
            $this->logger->error('#PSE', array('message' => $e->getMessage(), 'code' => $e->getCode(), 'line' => $e->getLine(), 'trace' => $e->getTraceAsString()));
            //throw new \Magento\Framework\Validator\Exception(__($e->getMessage()));
        }
        
        return $this->resultRedirectFactory->create()->setPath('checkout/cart'); 
    }
}