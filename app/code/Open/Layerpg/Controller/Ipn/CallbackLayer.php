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

class CallbackLayer extends \Magento\Framework\App\Action\Action implements CsrfAwareActionInterface
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Sales\Model\OrderFactory
     */
    protected $_orderFactory;

    /**
     * @var \Magento\Customer\Model\Session
     */
    protected $_customerSession;

    /**
     * @var \Magento\Quote\Api\CartRepositoryInterface
     */
    protected $quoteRepository;
    
    /**
     * @var \Psr\Log\LoggerInterface
     */
    protected $_logger;

    /**
     * @var \Magento\Quote\Model\Quote
     */
    protected $_quote;

    /**
     * @var \Tco\Checkout\Model\Checkout
     */
    protected $_paymentMethod;

    /**
     * @var \Tco\Checkout\Helper\Checkout
     */
    protected $_checkoutHelper;

    /**
     * @var \Magento\Quote\Api\CartManagementInterface
     */
    protected $cartManagement;

    /**
     * @var \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
     */
    protected $resultJsonFactory;



    /**
    * @param \Magento\Framework\App\Action\Context $context
    * @param \Magento\Sales\Model\OrderFactory $orderFactory
    * @param \Open\Layerpg\Model\PaymentMethod $paymentMethod
    * @param Magento\Sales\Model\Order\Email\Sender\OrderSender $orderSender
    * @param  \Psr\Log\LoggerInterface $logger
    */
    public function __construct(
		\Magento\Framework\App\Action\Context $context,
        \Magento\Customer\Model\Session $customerSession,
        \Magento\Checkout\Model\Session $checkoutSession,
        \Magento\Quote\Api\CartRepositoryInterface $quoteRepository,
        \Magento\Sales\Model\OrderFactory $orderFactory,		
        \Psr\Log\LoggerInterface $logger,
        \Open\Layerpg\Model\PaymentMethod $paymentMethod,
        \Open\Layerpg\Helper\layerHelper $checkoutHelper,
        \Magento\Quote\Api\CartManagementInterface $cartManagement,
        \Magento\Framework\Controller\Result\JsonFactory $resultJsonFactory
    ) {
        $this->_customerSession = $customerSession;
        $this->_checkoutSession = $checkoutSession;
        $this->quoteRepository = $quoteRepository;
        $this->_orderFactory = $orderFactory;
        $this->_paymentMethod = $paymentMethod;
        $this->_checkoutHelper = $checkoutHelper;
        $this->cartManagement = $cartManagement;
        $this->resultJsonFactory = $resultJsonFactory;
        $this->_logger = $logger;
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
     * Instantiate quote and checkout
     *
     * @return void
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    protected function initCheckout()
    {
        $quote = $this->getQuote();
        if (!$quote->hasItems() || $quote->getHasError()) {
            $this->getResponse()->setStatusHeader(403, '1.1', 'Forbidden');
            throw new \Magento\Framework\Exception\LocalizedException(__('We can\'t initialize checkout.'));
        }
    }
	
	/**
     * Cancel order, return quote to customer
     *
     * @param string $errorMsg
     * @return false|string
     */
    protected function _cancelPayment($errorMsg = '')
    {
        $gotoSection = false;
        $this->_checkoutHelper->cancelCurrentOrder($errorMsg);
        if ($this->_checkoutSession->restoreQuote()) {
            //Redirect to payment step
            $gotoSection = 'paymentMethod';
        }

        return $gotoSection;
    }
	
	/**
     * Get order object
     *
     * @return \Magento\Sales\Model\Order
     */
    protected function getOrderById($order_id)
    {
        $objectManager = \Magento\Framework\App\ObjectManager::getInstance();
        $order = $objectManager->get('Magento\Sales\Model\Order');
        $order_info = $order->loadByIncrementId($order_id);
        return $order_info;
    }

    /**
     * Get order object
     *
     * @return \Magento\Sales\Model\Order
     */
    protected function getOrder()
    {
        return $this->_orderFactory->create()->loadByIncrementId(
            $this->_checkoutSession->getLastRealOrderId()
        );
    }

    protected function getQuote()
    {
        if (!$this->_quote) {
            $this->_quote = $this->getCheckoutSession()->getQuote();
        }
        return $this->_quote;
    }

    protected function getCheckoutSession()
    {
        return $this->_checkoutSession;
    }

    public function getCustomerSession()
    {
        return $this->_customerSession;
    }

    public function getPaymentMethod()
    {
        return $this->_paymentMethod;
    }

    protected function getCheckoutHelper()
    {
        return $this->_checkoutHelper;
    }
	
    /**
    * Handle POST request to Layer callback endpoint.
    */
    public function execute()
    {
		$returnUrl='';
		
		try{
			
			$params = $this->getRequest()->getParams();
			
			//$this->_logger->error(json_encode($params));
								
			$returnUrl = $this->getCheckoutHelper()->getUrl('checkout');
			
			$this->messageManager->getMessages(true);//clear previous messages
			
			$paymentMethod = $this->getPaymentMethod();			
			$order = $this->getOrder();
			$payment=null;
			
			if($order->getPayment() == null && is_array($params))
			{
				$this->_logger->error("Order not found in session... loading");
				$order_id = $params['woo_order_id']; //sent with payment request as orderId
				
				if($order_id == '' or $order_id == null)
				{
					$this->_logger->error("Order id is null");
					$returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/failure');
					$this->getResponse()->setRedirect($returnUrl);
				}	
				$order= $this->getOrderById($order_id);
				
			}
			if($order)
				$payment = $order->getPayment();
			
			$processing_status = $paymentMethod->postProcessing($order, $payment, $params);
			
			if ($order && $payment && $processing_status=='') {
				$returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/success');
				$this->messageManager->addSuccessMessage(__('Payment Successful. Thank you for your order.'));				
			}
			else 
			{
				$this->messageManager->addErrorMessage(__($processing_status));
				$returnUrl = $this->getCheckoutHelper()->getUrl('checkout/onepage/failure');
			}
		} catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->messageManager->addExceptionMessage($e, $e->getMessage().'Ok');
        } catch (\Exception $e) {
            $this->messageManager->addExceptionMessage($e, __('We can\'t place the order.'));
        }
		
		$this->getResponse()->setRedirect($returnUrl);		
    }	
}
