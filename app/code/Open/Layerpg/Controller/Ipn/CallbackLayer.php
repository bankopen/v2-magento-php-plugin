<?php
/** 
 * @copyright  Open
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Open\Layerpg\Controller\Ipn;

use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Request\InvalidRequestException;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Sales\Model\Order;
use Magento\Framework\App\Action\Action as AppAction;

class CallbackLayer extends AppAction implements CsrfAwareActionInterface
{
    /**
    * @var \Open\Layer\Model\PaymentMethod
    */
    protected $_paymentMethod;

    /**
    * @var \Magento\Sales\Model\Order
    */
    protected $_order;

    /**
    * @var \Magento\Sales\Model\OrderFactory
    */
    protected $_orderFactory;

    /**
    * @var Magento\Sales\Model\Order\Email\Sender\OrderSender
    */
    protected $_orderSender;

    /**
    * @var \Psr\Log\LoggerInterface
    */
    protected $_logger;
	
	protected $request;

    /**
    * @param \Magento\Framework\App\Action\Context $context
    * @param \Magento\Sales\Model\OrderFactory $orderFactory
    * @param \Open\Layerpg\Model\PaymentMethod $paymentMethod
    * @param Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    * @param  \Psr\Log\LoggerInterface $logger
    */
    public function __construct(
    \Magento\Framework\App\Action\Context $context,
	\Magento\Framework\App\Request\Http $request,
    \Magento\Sales\Model\OrderFactory $orderFactory,
    \Open\Layerpg\Model\PaymentMethod $paymentMethod,
    \Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender,	
    \Psr\Log\LoggerInterface $logger
    ) {
        $this->_paymentMethod = $paymentMethod;
        $this->_orderFactory = $orderFactory;
        $this->_client = $this->_paymentMethod->getClient();
        $this->_orderSender = $orderSender;		
        $this->_logger = $logger;	
		$this->request = $request;
        parent::__construct($context);
    }

	// Bypass Magento2.3.2 CSRF validation
	public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
	
    /**
    * Handle POST request to Layer callback endpoint.
    */
    public function execute()
    {
        try {
            // Cryptographically verify authenticity of callback
            if($this->request->getPost())
			{				
				$this->_success();
				$this->paymentAction();
			}
			else
			{
	            $this->_logger->addError("Layer: no post back data received in callback");
				return $this->_failure();
			}
        } catch (Exception $e) {
            $this->_logger->addError("Layer: error processing callback");
            $this->_logger->addError($e->getMessage());
            return $this->_failure();
        }
		
		$this->_logger->addInfo("Layer Transaction END from Layer");
    }
	
	protected function paymentAction()
	{
		if ($this->getRequest()->isPost ()) {
			
			$postdata = $this->getRequest()->getPost ();
			$data = array(
				'layer_pay_token_id'    => $postdata['layer_pay_token_id'],
				'layer_order_amount'    => $postdata['layer_order_amount'],
				'woo_order_id'     		=> $postdata['woo_order_id'],
			);	
			$error="";
			$status="";
			
			$ordid = $data['woo_order_id'];
    	    $this->_loadOrder($ordid);
			
			if ($this->_paymentMethod->verify_hash($data,$_POST['hash']) && !empty($data['woo_order_id'])) {
				$payment_data = $this->_paymentMethod->get_payment_details($postdata['layer_payment_id']);
				
				if(isset($payment_data['error'])){
					$error = "Layer: an error occurred E14".$payment_data['error'];
				}

				if(empty($error) && isset($payment_data['id']) && !empty($payment_data)){
					if($payment_data['payment_token']['id'] != $data['layer_pay_token_id']){
						$error = "Layer: received layer_pay_token_id and collected layer_pay_token_id doesnt match";
					}
					elseif($data['layer_order_amount'] != $payment_data['amount']){
						$error = "Layer: received amount and collected amount doesnt match";
					}
					else {
						
						if($payment_data['status'] == 'authorized' || $payment_data['status'] == 'captured' ){
								$status = "Layer Payment captured: Payment ID ". $payment_data['id'];
								$this->_registerPaymentCapture ($ordid, $payment_data['amount'], $status);
								//$this->_logger->addInfo("Layer Response Order success..".$txMsg);
								$redirectUrl = $this->_paymentMethod->getSuccessUrl();
								$this->_redirect($redirectUrl);
						}
						if($payment_data['status'] == 'cancelled' || $payment_data['status'] == 'failed' ){
								$status = "Layer Payment cancelled/failed: Payment ID ". $payment_data['id'];                        
								$this->_createComment($status, true);
								$this->_order->cancel()->save();
								$this->_logger->addError($status);
								$this->messageManager->addError("<strong>Error:</strong> ".$status);
								$this->_redirect('checkout/onepage/failure');								
						}	
					}
				} else {
					$error = "invalid payment data received E98";
					
					$this->_createComment('Layer:'.$error);
					$this->_order->cancel()->save();				
					//$this->_logger->addInfo("Layer Response Order cancelled ..");
					$this->messageManager->addError("<strong>Error:</strong>". $error );
					$redirectUrl = $this->_paymentMethod->getCancelUrl();
					$this->_redirect($redirectUrl);	
				}
			}
		}
	}
	

	//AA - To review - required 
    protected function _registerPaymentCapture($transactionId, $amount, $message)
    {
        $payment = $this->_order->getPayment();
		
		
        $payment->setTransactionId($transactionId)       
        ->setPreparedMessage($this->_createComment($message))
        ->setShouldCloseParentTransaction(true)
        ->setIsTransactionClosed(0)
		->setAdditionalInformation('layerpg','Layer')		
        ->registerCaptureNotification($amount,true);
		
		$this->_order->setTotalPaid($amount); 		
		$this->_order->setState(Order::STATE_PROCESSING)->setStatus(Order::STATE_PROCESSING);
        $this->_order->save();

        $invoice = $payment->getCreatedInvoice();
        if ($invoice && !$this->_order->getEmailSent()) {
            $this->_orderSender->send($this->_order);
            $this->_order->addStatusHistoryComment(
                __('You notified customer about invoice #%1.', $invoice->getIncrementId())
            )->setIsCustomerNotified(
                true
            )->save();
        }
    }

	//AA Done
    protected function _loadOrder($order_id)
    {
        $this->_order = $this->_orderFactory->create()->loadByIncrementId($order_id);

        if (!$this->_order && $this->_order->getId()) {
            throw new Exception('Could not find Magento order with id $order_id');
        }
    }

	//AA Done
    protected function _success()
    {
        $this->getResponse()
             ->setStatusHeader(200);
    }

	//AA Done
    protected function _failure()
    {
        $this->getResponse()
             ->setStatusHeader(400);
    }

    /**
    * Returns the generated comment or order status history object.
    *
    * @return string|\Magento\Sales\Model\Order\Status\History
    */
	//AA Done
    protected function _createComment($message = '')
    {       
        if ($message != '')
        {
            $message = $this->_order->addStatusHistoryComment($message);
            $message->setIsCustomerNotified(null);
        }
		
        return $message;
    }
	
}
