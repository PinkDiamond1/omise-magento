<?php
namespace Omise\Payment\Block\Checkout\Onepage\Success;

class TescoAdditionalInformation extends \Magento\Framework\View\Element\Template
{
    /**
    * Recipient email config path
    */
    const XML_PATH_EMAIL_RECIPIENT = 'test/email/send_email';
    /**
    * @var \Magento\Framework\Mail\Template\TransportBuilder
    */
    protected $_transportBuilder;
    
    /**
    * @var \Magento\Framework\Translate\Inline\StateInterface
    */
    protected $inlineTranslation;
    
    /**
    * @var \Magento\Framework\Escaper
    */
    protected $_escaper;
    
    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $_checkoutSession;

    /**
     * @param \Magento\Framework\View\Element\Template\Context $context
     * @param \Magento\Checkout\Model\Session $checkoutSession
     * @param array $data
     */
    private $log;

    public function __construct(
        \Magento\Framework\View\Element\Template\Context $context,
        \Magento\Checkout\Model\Session $checkoutSession,
        array $data = []
    ) {
        $this->_checkoutSession = $checkoutSession;
        parent::__construct($context, $data);
    }

    /**
     * Return HTML code with tesco lotus payment infromation
     *
     * @return string
     */
    protected function _toHtml()
    {
        $paymentData = $this->_checkoutSession->getLastRealOrder()->getPayment()->getData();
        if ($paymentData['additional_information']['payment_type'] !== 'bill_payment_tesco_lotus') {
            return parent::_toHtml();
        }
        $barcode =  $paymentData['additional_information']['barcode'];

        if (!$barcode) {
            return parent::_toHtml();
        }

        $orderCurrency = $this->_checkoutSession->getLastRealOrder()->getOrderCurrency()->getCurrencyCode();

        $this->addData([
            'tesco_code_image' => $barcode,
            'order_amount' => number_format($paymentData['amount_ordered'], 2) .' '.$orderCurrency
        ]);
        
        return parent::_toHtml();
    }
}
