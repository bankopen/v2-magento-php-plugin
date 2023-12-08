<?php
/**
 *
 * @copyright  Open
 * @license    http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

namespace Open\Layerpg\Controller\Checkout;

class Success extends \Magento\Framework\App\Action\Action
{
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @var \Magento\Framework\UrlInterface
     */
    protected $_urlBuilder;

    /**
     * @var \Magento\Store\Model\StoreManagerInterface
     */
    protected $_storeManager;

    /**
     * @param \Magento\Framework\App\Action\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param \Magento\Framework\UrlInterface $urlBuilder
     * @param \Magento\Store\Model\StoreManagerInterface $storeManager
     */
    public function __construct(
      \Magento\Framework\App\Action\Context $context,
      \Magento\Checkout\Model\Session $checkoutSession      
    ) {
        $this->_checkoutSession = $checkoutSession;
        $this->_urlBuilder = $context->getUrlInterface();
        $this->_storeManager = $context->getStoreManagerInterface();
        parent::__construct($context);
    }

    /**
    * Unset the quote and redirect to checkout success.
    */
    public function execute()
    {
        $this->getResponse()->setRedirect(
            $this->_getUrl('checkout/onepage/success')
        );
    }

    /**
    * Build URL for store.
    *
    * @param string $path
    * @param int $storeId
    * @param bool|null $secure
    *
    * @return string
    */
    protected function _getUrl($path, $secure = null)
    {
        $store = $this->_storeManager->getStore(null);

        return $this->_urlBuilder->getUrl(
            $path,
            ['_store' => $store, '_secure' => $secure === null ? $store->isCurrentlySecure() : $secure]
        );
    }
}
