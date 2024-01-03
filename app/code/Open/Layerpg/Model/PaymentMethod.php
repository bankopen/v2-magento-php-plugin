<?php
/** 
 * @copyright  Open
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Open\Layerpg\Model;

use Magento\Sales\Model\Order\Payment;
use Magento\Sales\Model\Order\Payment\Transaction;

class PaymentMethod extends \Magento\Payment\Model\Method\AbstractMethod
{
    protected $_code = 'layerpg';
    protected $_isInitializeNeeded = true;

    /**
    * @var \Magento\Framework\Exception\LocalizedExceptionFactory
    */
    protected $_exception;

    /**
    * @var \Magento\Sales\Api\TransactionRepositoryInterface
    */
    protected $_transactionRepository;

    /**
    * @var Transaction\BuilderInterface
    */
    protected $_transactionBuilder;

    /**
    * @var \Magento\Framework\UrlInterface
    */
    protected $_urlBuilder;

    /**
    * @var \Magento\Sales\Model\OrderFactory
    */
    protected $_orderFactory;
	protected $_countryHelper;
    /**
    * @var \Magento\Store\Model\StoreManagerInterface
    */
    protected $_storeManager;
	
	protected $adnlinfo;
	protected $title;
	
	protected $_accesskey;
	protected $_secretkey;
	protected $_sandbox;

	const BASE_URL_SANDBOX = "https://sandbox-icp-api.bankopen.co/api";
    const BASE_URL_UAT = "https://icp-api.bankopen.co/api";
	
    /**
    * @param \Magento\Framework\UrlInterface $urlBuilder
    * @param \Magento\Framework\Exception\LocalizedExceptionFactory $exception
    * @param \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository
    * @param Transaction\BuilderInterface $transactionBuilder
    * @param \Magento\Sales\Model\OrderFactory $orderFactory
    * @param \Magento\Store\Model\StoreManagerInterface $storeManager
    * @param \Magento\Framework\Model\Context $context
    * @param \Magento\Framework\Registry $registry
    * @param \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory
    * @param \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory
    * @param \Magento\Payment\Helper\Data $paymentData
    * @param \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig
    * @param \Magento\Payment\Model\Method\Logger $logger
    * @param \Magento\Framework\Model\ResourceModel\AbstractResource $resource
    * @param \Magento\Framework\Data\Collection\AbstractDb $resourceCollection
    * @param array $data
    */
    public function __construct(
      \Magento\Framework\UrlInterface $urlBuilder,
      \Magento\Framework\Exception\LocalizedExceptionFactory $exception,
      \Magento\Sales\Api\TransactionRepositoryInterface $transactionRepository,
      \Magento\Sales\Model\Order\Payment\Transaction\BuilderInterface $transactionBuilder,
      \Magento\Sales\Model\OrderFactory $orderFactory,
      \Magento\Store\Model\StoreManagerInterface $storeManager,
      \Magento\Framework\Model\Context $context,
      \Magento\Framework\Registry $registry,
      \Magento\Framework\Api\ExtensionAttributesFactory $extensionFactory,
      \Magento\Framework\Api\AttributeValueFactory $customAttributeFactory,
      \Magento\Payment\Helper\Data $paymentData,
      \Magento\Framework\App\Config\ScopeConfigInterface $scopeConfig,
      \Magento\Payment\Model\Method\Logger $logger,
      \Magento\Framework\Model\ResourceModel\AbstractResource $resource = null,
      \Magento\Framework\Data\Collection\AbstractDb $resourceCollection = null,
      array $data = []
    ) {
      $this->_urlBuilder = $urlBuilder;
      $this->_exception = $exception;
      $this->_transactionRepository = $transactionRepository;
      $this->_transactionBuilder = $transactionBuilder;
      $this->_orderFactory = $orderFactory;
      $this->_storeManager = $storeManager;
	  $this->_countryHelper = \Magento\Framework\App\ObjectManager::getInstance()->get('\Magento\Directory\Model\Country');      
	  
	  
	  parent::__construct(
          $context,
          $registry,
          $extensionFactory,
          $customAttributeFactory,
          $paymentData,
          $scopeConfig,
          $logger,
          $resource,
          $resourceCollection,
          $data
      );
	  
	  $this->_sandbox =  $this->getConfigData('sandbox');
	  $this->_accesskey = $this->getConfigData('accesskey');
	  $this->_secretkey = $this->getConfigData('secretkey');
    }

    /**
     * Instantiate state and set it to state object.
     *
     * @param string $paymentAction
     * @param \Magento\Framework\DataObject $stateObject
     */
    public function initialize($paymentAction, $stateObject)
    {
        $payment = $this->getInfoInstance();
        $order = $payment->getOrder();
        $order->setCanSendNewEmailFlag(false);		
		
        $stateObject->setState(\Magento\Sales\Model\Order::STATE_PENDING_PAYMENT);
        $stateObject->setStatus('pending_payment');
        $stateObject->setIsNotified(false);
    }

	
	public function create_hash($data){
		ksort($data);
		$hash_string = $this->_accesskey;
		foreach ($data as $key=>$value){
			$hash_string .= '|'.$value;
		}
		return hash_hmac("sha256",$hash_string,$this->_secretkey);
	}
	
	public function verify_hash($data,$rec_hash){
		$gen_hash = $this->create_hash($data);
		if($gen_hash === $rec_hash){
			return true;
		}
		return false;
	}
	
	public function getPostHTML($order, $storeId = null)
    {
		$txnid = $order->getIncrementId();
    	$amount = $order->getGrandTotal();
        $amount = number_format((float)$amount, 2, '.', '');
        	
		$remote_script = "<script type='application/javascript' id='open_money_layer' src='https://payments.open.money/layer/js'></script>";
			
		if($this->_sandbox){			
			$remote_script = "<script type='application/javascript' id='open_money_layer' src='https://sandbox-payments.open.money/layer/js'></script>";
		}
	
		$currency = $order->getOrderCurrencyCode();
       	$billingAddress = $order->getBillingAddress();
		$firstname = $billingAddress->getData('firstname');
		$lastname = $billingAddress->getData('lastname');		
		$email = $billingAddress->getData('email');
		$phone = $billingAddress->getData('telephone');		
		
		$layer_payment_token_data = $this->create_payment_token([
                'amount' => $amount,
                'currency' => $currency,
                'name'  => $firstname.' '.$lastname,
                'email_id' => $email,
                'contact_number' => $phone                
            ]);
		
		$surl = self::getReturnUrl();			
		$error="";
		$payment_token_data = "";
		
		if(empty($error) && isset($layer_payment_token_data['error'])){
			$error = 'E55 Payment error. ' . $layer_payment_token_data['error'];          
		}

		if(empty($error) && (!isset($layer_payment_token_data["id"]) || empty($layer_payment_token_data["id"]))){				
			$error = 'Payment error. ' . 'Layer token ID cannot be empty';        
		}   
    
		if(empty($error))
			$payment_token_data = $this->get_payment_token($layer_payment_token_data["id"]);
    
		if(empty($error) && empty($payment_token_data))
			$error = 'Layer token data is empty...';
		
		if(empty($error) && isset($payment_token_data['error'])){
            $error = 'E56 Payment error. ' . $payment_token_data['error'];            
        }

        if(empty($error) && $payment_token_data['status'] == "paid"){
            $error = "Layer: this order has already been paid";            
        }

        if(empty($error) && $payment_token_data['amount'] != $amount){
            $error = "Layer: an amount mismatch occurred";
        }
		
    
		if(empty($error) && !empty($payment_token_data)){
			$jsdata['payment_token_id'] = html_entity_decode((string) $payment_token_data['id'],ENT_QUOTES,'UTF-8');
			$jsdata['accesskey']  = html_entity_decode((string) $this->_accesskey,ENT_QUOTES,'UTF-8');
			$jsdata['retry'] = 0;//if retry set 1
        
			$hash = $this->create_hash(array(
				'layer_pay_token_id'    => $payment_token_data['id'],
				'layer_order_amount'    => $payment_token_data['amount'],
				'woo_order_id'    => $txnid,
				));
				
			$html =  "<form action='".$surl."' method='post' style='display: none' name='layer_payment_int_form'>
            <input type='hidden' name='layer_pay_token_id' value='".$payment_token_data['id']."'>
            <input type='hidden' name='woo_order_id' value='".$txnid."'>
            <input type='hidden' name='layer_order_amount' value='".$payment_token_data['amount']."'>
            <input type='hidden' id='layer_payment_id' name='layer_payment_id' value=''>
            <input type='hidden' id='fallback_url' name='fallback_url' value='index.php'>
            <input type='hidden' name='hash' value='".$hash."'>
            </form>";
			
			$html .= $remote_script;
			
			$html .= "<script type='text/javascript'>";						
			$html .= "function triggerLayer() {							
							Layer.checkout(
							{
								token: '".$jsdata['payment_token_id']."',
								accesskey: '".$jsdata['accesskey']."'
							},
							function (response) {
								console.log(response)
								if(response !== null || response.length > 0 ){
									if(response.payment_id !== undefined || response.payment_id !== '' || response.payment_id != null ){
										document.getElementById('layer_payment_id').value = response.payment_id;
									}
								}
								document.layer_payment_int_form.submit();
							},
							function (err) {
								alert(err.message);
							});	
						}
						
						var checkExist = setInterval(function() {
							if (typeof Layer !== 'undefined') {
								console.log('Layer Loaded...');
								clearInterval(checkExist);
								triggerLayer();
							}
							else {
								console.log('Layer undefined...');
							}
						}, 1000);																			
				</script>";
			
			return [
                'error' => '',
				'data'=> $html
            ];
		}
		else
			return [
                'error' => $error,
				'data'=> ''
            ];
    }

    public function getOrderPlaceRedirectUrl($storeId = null)
    {
        return $this->_getUrl('layerpg/checkout/start', $storeId);
    }

	protected function addHiddenField($arr)
	{
		$nm = $arr['name'];
		$vl = $arr['value'];	
		$input = "<input name='".$nm."' type='hidden' value='".$vl."' />";	
		
		return $input;
	}
	
    /**
     * Get return URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA may not be required
    public function getSuccessUrl($storeId = null)
    {
        return $this->_getUrl('checkout/onepage/success', $storeId);
    }

	/**
     * Get return (IPN) URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA Done
    
	 public function getReturnUrl($storeId = null)
    {
        return $this->_getUrl('layerpg/ipn/callbacklayer', $storeId, false);
    }
	/**
     * Get cancel URL.
     *
     * @param int|null $storeId
     *
     * @return string
     */
	 //AA Not required
    public function getCancelUrl($storeId = null)
    {
        return $this->_getUrl('checkout/onepage/failure', $storeId);
    }

	/**
     * Build URL for store.
     *
     * @param string    $path
     * @param int       $storeId
     * @param bool|null $secure
     *
     * @return string
     */
	 //AA Done
    protected function _getUrl($path, $storeId, $secure = null)
    {
        $store = $this->_storeManager->getStore($storeId);

        return $this->_urlBuilder->getUrl(
            $path,
            ['_store' => $store, '_secure' => $secure === null ? $store->isCurrentlySecure() : $secure]
        );
    }
	
	protected function create_payment_token($data){

        try {
            $pay_token_request_data = array(
                'amount'   			=> $data['amount'] ?? NULL,
                'currency' 			=> $data['currency'] ?? NULL,
                'name'     			=> $data['name'] ?? NULL,
                'email_id' 			=> $data['email_id'] ?? NULL,
                'contact_number' 	=> $data['contact_number'] ?? NULL,
                'mtx'    			=> $data['mtx'] ?? NULL,
                'udf'    			=> $data['udf'] ?? NULL,
            );

            $pay_token_data = $this->http_post($pay_token_request_data,"payment_token");

            return $pay_token_data;
        } catch (Exception $e){			
            return [
                'error' => $e->getMessage()
            ];

        } catch (Throwable $e){
			
			return [
                'error' => $e->getMessage()
            ];
        }
    }

    protected function get_payment_token($payment_token_id){

        if(empty($payment_token_id)){

            throw new Exception("payment_token_id cannot be empty");
        }

        try {

            return $this->http_get("payment_token/".$payment_token_id);

        } catch (Exception $e){

            return [
                'error' => $e->getMessage()
            ];

        } catch (Throwable $e){

            return [
                'error' => $e->getMessage()
            ];
        }

    }

    public function get_payment_details($payment_id){

        if(empty($payment_id)){

            throw new Exception("payment_id cannot be empty");
        }

        try {

            return $this->http_get("payment/".$payment_id);

        } catch (Exception $e){
			
            return [
                'error' => $e->getMessage()
            ];

        } catch (Throwable $e){

            return [
                'error' => $e->getMessage()
            ];
        }

    }


    protected function build_auth($body,$method){

        $time_stamp = trim(time());
        unset($body['udf']);

        if(empty($body)){

            $token_string = $time_stamp.strtoupper($method);

        } else {            
            $token_string = $time_stamp.strtoupper($method).json_encode($body);
        }

        $token = trim(hash_hmac("sha256",$token_string,$this->_secretkey));

        return array(                       
            'Content-Type: application/json',                                 
            'Authorization: Bearer '.$this->_accesskey.':'.$this->_secretkey,
            'X-O-Timestamp: '.$time_stamp
        );

    }


    protected function http_post($data,$route){

        foreach (@$data as $key=>$value){

            if(empty($data[$key])){

                unset($data[$key]);
            }
        }

        if($this->_sandbox){
            $url = self::BASE_URL_SANDBOX."/".$route;
        } else {
            $url = self::BASE_URL_UAT."/".$route;
        }

        $header = $this->build_auth($data,"post");
        
        try
        {
            $curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $url);
		    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		    curl_setopt($curl, CURLOPT_SSLVERSION, 6);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_MAXREDIRS,10);
		    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		    curl_setopt($curl, CURLOPT_ENCODING, '');		
		    curl_setopt($curl, CURLOPT_TIMEOUT, 60);
            curl_setopt($curl, CURLOPT_CUSTOMREQUEST, 'POST');
            curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data, JSON_HEX_APOS|JSON_HEX_QUOT ));
            
		    $response = curl_exec($curl);
            $curlerr = curl_error($curl);
            
            if($curlerr != '')
            {
                return [
                    "error" => "Http Post failed",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Post failed",
                "error_data" => $e->getMessage(),
            ];
        }           
        
    }

    protected function http_get($route){

        if($this->_sandbox){
			$url = self::BASE_URL_SANDBOX."/".$route;
        } else {			
            $url = self::BASE_URL_UAT."/".$route;
		}

        $header = $this->build_auth($data = [],"get");

        try
        {           
            $curl = curl_init();
		    curl_setopt($curl, CURLOPT_URL, $url);
		    curl_setopt($curl, CURLOPT_HTTPHEADER, $header);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
		    curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
		    curl_setopt($curl, CURLOPT_SSLVERSION, 6);
		    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
		    curl_setopt($curl, CURLOPT_FOLLOWLOCATION, true);
		    curl_setopt($curl, CURLOPT_ENCODING, '');		
		    curl_setopt($curl, CURLOPT_TIMEOUT, 60);		   
            $response = curl_exec($curl);
            $curlerr = curl_error($curl);
            if($curlerr != '')
            {
                return [
                    "error" => "Http Get failed",
                    "error_data" => $curlerr,
                ];
            }
            return json_decode($response,true);
        }
        catch(Exception $e)
        {
            return [
                "error" => "Http Get failed",
                "error_data" => $e->getMessage(),
            ];
        }
    }
}
