<?php
namespace Omise\Payment\Controller\Callback;

use Exception;
use Magento\Checkout\Model\Session;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Payment\Transaction;
use Omise\Payment\Model\Omise;
use Omise\Payment\Model\Api\Charge;
use Omise\Payment\Model\Config\Internetbanking;
use Omise\Payment\Model\Config\Alipay;
use Omise\Payment\Model\Config\Pointsciti;
use Omise\Payment\Model\Config\Installment;
use Omise\Payment\Model\Config\Truemoney;
use Omise\Payment\Model\Config\Fpx;
use Omise\Payment\Model\Config\Alipayplus;
use Omise\Payment\Model\Config\Mobilebanking;
use Omise\Payment\Model\Config\Rabbitlinepay;
use Omise\Payment\Model\Config\Ocbcpao;
use Omise\Payment\Model\Config\Grabpay;
use Omise\Payment\Model\Config\Boost;
use Omise\Payment\Model\Config\DuitnowOBW;
use Omise\Payment\Model\Config\DuitnowQR;
use Omise\Payment\Model\Config\MaybankQR;
use Omise\Payment\Model\Config\Shopeepay;
use Omise\Payment\Model\Config\Touchngo;
use Magento\Framework\Exception\LocalizedException;
use Omise\Payment\Helper\OmiseHelper;
use Omise\Payment\Helper\OmiseEmailHelper;
use Omise\Payment\Model\Config\Cc as Config;
use Magento\Checkout\Model\Session as CheckoutSession;

class Offsite extends Action
{
    /**
     * @var string
     */
    const PATH_CART    = 'checkout/cart';
    const PATH_SUCCESS = 'checkout/onepage/success';

    /**
     * @var \Magento\Checkout\Model\Session
     */
    protected $session;

    /**
     * @var \Omise\Payment\Model\Omise
     */
    protected $omise;

    /**
     * @var \Omise\Payment\Model\Api\Charge
     */
    protected $charge;

    /**
     * @var \Omise\Payment\Helper\OmiseHelper
     */
    protected $helper;

    /**
     * @var \Omise\Payment\Helper\OmiseEmailHelper
     */
    protected $emailHelper;

    public function __construct(
        Context $context,
        Session $session,
        Omise   $omise,
        Charge  $charge,
        OmiseHelper $helper,
        OmiseEmailHelper $emailHelper,
        Config $config,
        CheckoutSession $checkoutSession
    ) {
        parent::__construct($context);

        $this->session = $session;
        $this->omise   = $omise;
        $this->charge  = $charge;
        $this->helper  = $helper;
        $this->emailHelper = $emailHelper;
        $this->config = $config;
        $this->checkoutSession  = $checkoutSession;

        $this->omise->defineUserAgent();
        $this->omise->defineApiVersion();
        $this->omise->defineApiKeys();
    }

    /**
     * @return void
     */
    public function execute()
    {
        $order = $this->session->getLastRealOrder();

        if (! $order->getId()) {
            $this->messageManager->addErrorMessage(__('The order session no longer exists, please make an order
            again or contact our support if you have any questions.'));

            return $this->redirect(self::PATH_CART);
        }

        if ($order->getState() === Order::STATE_PROCESSING) {
            return $this->redirect(self::PATH_SUCCESS);
        }

        if ($order->getState() !== Order::STATE_PENDING_PAYMENT) {
            $this->invalid($order, __('Invalid order status, cannot validate the payment. Please contact our
            support if you have any questions.'));

            return $this->redirect(self::PATH_CART);
        }

        if (! $payment = $order->getPayment()) {
            $this->invalid($order, __('Cannot retrieve a payment detail from the request. Please contact our
            support if you have any questions.'));

            return $this->redirect(self::PATH_CART);
        }
        
        $paymentMethod = $payment->getMethod();

        if (!$this->helper->isOffsitePaymentMethod($paymentMethod)) {
            $this->invalid(
                $order,
                __('Invalid payment method. Please contact our support if you have any questions.')
            );
            return $this->redirect(self::PATH_CART);
        }

        if (! $charge_id = $payment->getAdditionalInformation('charge_id')) {
            $this->cancel(
                $order,
                __('Cannot retrieve a charge reference id. Please contact our support to confirm your payment.')
            );
            $this->session->restoreQuote();

            return $this->redirect(self::PATH_CART);
        }

        try {
            $charge = $this->charge->find($charge_id);

            if (! $charge instanceof \Omise\Payment\Model\Api\BaseObject) {
                throw new LocalizedException(
                    __('Couldn\'t retrieve charge transaction. Please contact administrator.')
                );
            }

            if ($charge instanceof \Omise\Payment\Model\Api\Error) {
                // restoring the cart
                $this->checkoutSession->restoreQuote();
                throw new LocalizedException(__($charge->getMessage()));
            }

            if ($charge->isFailed()) {
                // restoring the cart
                $this->checkoutSession->restoreQuote();

                throw new LocalizedException(
                    __('Payment failed. ' . ucfirst($charge->failure_message) . ', please contact our support
                    if you have any questions.')
                );
            }

            // Do not proceed if webhook is enabled
            if ($this->config->isWebhookEnabled()) {
                return $this->redirect(self::PATH_SUCCESS);
            }

            $payment->setTransactionId($charge->id);
            $payment->setLastTransId($charge->id);

            if ($charge->isSuccessful()) {
                // Update order state and status.
                $order->setState(Order::STATE_PROCESSING);
                $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PROCESSING));

                $invoice = $this->helper->createInvoiceAndMarkAsPaid($order, $charge->id);
                $this->emailHelper->sendInvoiceAndConfirmationEmails($order);
                
                switch ($paymentMethod) {
                    case Internetbanking::CODE:
                        $dispPaymentMethod = "Internet Banking";
                        break;
                    case Installment::CODE:
                        $dispPaymentMethod = "Installment";
                        break;
                    case Alipay::CODE:
                        $dispPaymentMethod = "Alipay";
                        break;
                    case Truemoney::CODE:
                        $dispPaymentMethod = "True Money";
                        break;
                    case Pointsciti::CODE:
                        $dispPaymentMethod = "Citi Pay with Points";
                        break;
                    case Fpx::CODE:
                        $dispPaymentMethod = "FPX";
                        break;
                    case Alipayplus::ALIPAY_CODE:
                        $dispPaymentMethod = "Alipay (Alipay+ Partner)";
                        break;
                    case Alipayplus::ALIPAYHK_CODE:
                        $dispPaymentMethod = "AlipayHK (Alipay+ Partner)";
                        break;
                    case Alipayplus::DANA_CODE:
                        $dispPaymentMethod = "DANA (Alipay+ Partner)";
                        break;
                    case Alipayplus::GCASH_CODE:
                        $dispPaymentMethod = "GCash (Alipay+ Partner)";
                        break;
                    case Alipayplus::KAKAOPAY_CODE:
                        $dispPaymentMethod = "Kakao Pay (Alipay+ Partner)";
                        break;
                    case Touchngo::CODE:
                        $dispPaymentMethod = "Touch`n Go eWallet";
                        break;
                    case Mobilebanking::CODE:
                        $dispPaymentMethod = "Mobile Banking";
                        break;
                    case Rabbitlinepay::CODE:
                        $dispPaymentMethod = "Rabbit LINE Pay";
                        break;
                    case Ocbcpao::CODE:
                        $dispPaymentMethod = "OCBC Pay Anyone";
                        break;
                    case Grabpay::CODE:
                        $dispPaymentMethod = "GrabPay";
                        break;
                    case Boost::CODE:
                        $dispPaymentMethod = "Boost";
                        break;
                    case DuitnowOBW::CODE:
                        $dispPaymentMethod = "DuitNow Online Banking/Wallets";
                        break;
                    case DuitnowQR::CODE:
                        $dispPaymentMethod = "DuitNow QR";
                        break;
                    case MaybankQR::CODE:
                        $dispPaymentMethod = "Maybank QR";
                        break;
                    case Shopeepay::CODE:
                        $dispPaymentMethod = "ShopeePay";
                        break;
                }
                
                // Add transaction.
                $payment->addTransactionCommentsToOrder(
                    $payment->addTransaction(Transaction::TYPE_PAYMENT, $invoice),
                    __(
                        "Amount of %1 has been paid via Omise $dispPaymentMethod payment",
                        $order->getBaseCurrency()->formatTxt($invoice->getBaseGrandTotal())
                    )
                );

                $order->save();
                return $this->redirect(self::PATH_SUCCESS);
            }

            // Update order state and status.
            $order->setState(Order::STATE_PAYMENT_REVIEW);
            $order->setStatus($order->getConfig()->getStateDefaultStatus(Order::STATE_PAYMENT_REVIEW));

            // Add transaction.
            $transaction = $payment->addTransaction(Transaction::TYPE_PAYMENT);
            $transaction->setIsClosed(false);
            $payment->addTransactionCommentsToOrder(
                $transaction,
                __('The payment has been processing.<br/>Due to the Bank process, this might takes a few seconds
                or up-to an hour. Please click "Accept" or "Deny" the payment manually once the result has been 
                updated (you can check at Omise Dashboard).')
            );

            $order->save();

            // TODO: Should redirect users to a page that tell users that
            //       their payment is in review instead of success page.
            return $this->redirect(self::PATH_SUCCESS);
        } catch (Exception $e) {
            $this->cancel($order, $e->getMessage());

            return $this->redirect(self::PATH_CART);
        }
    }

    /**
     * @param  \Magento\Sales\Model\Order $order
     *
     * @return \Magento\Sales\Api\Data\InvoiceInterface
     */
    protected function invoice(Order $order)
    {
        return $order->getInvoiceCollection()->getLastItem();
    }

    /**
     * @param  string $path
     *
     * @return \Magento\Framework\App\ResponseInterface
     */
    protected function redirect($path)
    {
        return $this->_redirect($path, ['_secure' => true]);
    }

    /**
     * @param \Magento\Sales\Model\Order       $order
     * @param \Magento\Framework\Phrase|string $message
     */
    protected function invalid(Order $order, $message)
    {
        $order->addStatusHistoryComment($message);
        $order->save();

        $this->messageManager->addErrorMessage($message);
    }

    /**
     * @param \Magento\Sales\Model\Order       $order
     * @param \Magento\Framework\Phrase|string $message
     */
    protected function cancel(Order $order, $message)
    {
        if ($order->hasInvoices()) {
            $invoice = $this->invoice($order);
            $invoice->cancel();
            $order->addRelatedObject($invoice);
        }

        $order->registerCancellation($message)->save();
        $this->messageManager->addErrorMessage($message);
    }
}
