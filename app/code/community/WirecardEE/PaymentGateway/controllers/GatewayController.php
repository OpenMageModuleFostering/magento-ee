<?php
/**
 * Shop System Plugins:
 * - Terms of Use can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/_TERMS_OF_USE
 * - License can be found under:
 * https://github.com/wirecard/magento-ee/blob/master/LICENSE
 */

use Wirecard\PaymentSdk\BackendService;
use Wirecard\PaymentSdk\Entity\Redirect;
use Wirecard\PaymentSdk\Response\SuccessResponse;
use Wirecard\PaymentSdk\TransactionService;
use WirecardEE\PaymentGateway\Actions\Action;
use WirecardEE\PaymentGateway\Actions\ErrorAction;
use WirecardEE\PaymentGateway\Actions\RedirectAction;
use WirecardEE\PaymentGateway\Actions\ViewAction;
use WirecardEE\PaymentGateway\Data\OrderSummary;
use WirecardEE\PaymentGateway\Exception\UnknownActionException;
use WirecardEE\PaymentGateway\Mail\MerchantNotificationMail;
use WirecardEE\PaymentGateway\Mapper\BasketMapper;
use WirecardEE\PaymentGateway\Mapper\UserMapper;
use WirecardEE\PaymentGateway\Service\NotificationHandler;
use WirecardEE\PaymentGateway\Service\PaymentFactory;
use WirecardEE\PaymentGateway\Service\PaymentHandler;
use WirecardEE\PaymentGateway\Service\ReturnHandler;
use WirecardEE\PaymentGateway\Service\SessionManager;

/**
 * Frontend controller to handle payment processing/return/notify/cancel actions.
 *
 * @since 1.0.0
 */
class WirecardEE_PaymentGateway_GatewayController extends Mage_Core_Controller_Front_Action
{
    /**
     * Gets payment from `PaymentFactory`, assembles the `OrderSummary` and executes the payment through the
     * `PaymentHandler` service. Further action depends on the response from the handler.
     *
     * @return Action
     * @throws UnknownActionException
     * @throws Mage_Core_Exception
     * @throws \WirecardEE\PaymentGateway\Exception\UnknownPaymentException
     *
     * @since 1.0.0
     */
    public function indexAction()
    {
        $paymentName = $this->getRequest()->getParam('method');
        $payment     = (new PaymentFactory())->create($paymentName);
        $handler     = new PaymentHandler(
            $this->getHelper()->getTransactionManager(),
            $this->getHelper()->getLogger()
        );
        $order       = $this->getCheckoutSession()->getLastRealOrder();

        /** @var Mage_Core_Model_Session $session */
        $session        = Mage::getSingleton("core/session", ["name" => "frontend"]);
        $sessionManager = new SessionManager($session);

        $this->getHelper()->validateBasket();

        $action = $handler->execute(
            new OrderSummary(
                $payment,
                $order,
                new BasketMapper($order, $payment->getTransaction()),
                new UserMapper(
                    $order,
                    $this->getHelper()->getClientIp(),
                    Mage::app()->getLocale()->getLocaleCode()
                ),
                $this->getHelper()->getDeviceFingerprintId($payment->getPaymentConfig()->getTransactionMAID()),
                $sessionManager->getPaymentData()
            ),
            new TransactionService(
                $payment->getTransactionConfig($order->getBaseCurrency()->getCode()),
                $this->getHelper()->getLogger()
            ),
            new Redirect(
                $this->getUrl('paymentgateway/gateway/return', ['method' => $paymentName]),
                $this->getUrl('paymentgateway/gateway/cancel', ['method' => $paymentName])
            ),
            $this->getUrl('paymentgateway/gateway/notify', ['method' => $paymentName])
        );

        $this->handleAction($action);
        return $action;
    }

    /**
     * After paying the user gets redirected to this action, where the `ReturnHandler` takes care about what to do
     * next (e.g. redirecting to the "Thank you" page, rendering templates, ...).
     *
     * @return Action
     * @throws UnknownActionException
     * @throws \WirecardEE\PaymentGateway\Exception\UnknownPaymentException
     *
     * @since 1.0.0
     */
    public function returnAction()
    {
        $returnHandler = new ReturnHandler(
            $this->getHelper()->getTransactionManager(),
            $this->getHelper()->getLogger()
        );
        $request       = $this->getRequest();
        $payment       = (new PaymentFactory())->create($request->getParam('method'));
        $order         = $this->getCheckoutSession()->getLastRealOrder();

        try {
            $response = $returnHandler->handleRequest(
                $payment,
                $request,
                new TransactionService(
                    $payment->getTransactionConfig($order->getBaseCurrency()->getCode()),
                    $this->getHelper()->getLogger()
                )
            );

            $this->getHelper()
                 ->getLogger()
                 ->info("Called return action", ['response' => $response->getRawData()]);

            $action = $response instanceof SuccessResponse
                ? $this->updateOrder($order)
                : $returnHandler->handleResponse($response, $order);
        } catch (\Exception $e) {
            $this->getHelper()->getLogger()->error($e->getMessage());
            $action = new ErrorAction(0, 'Return processing failed');
        }

        $this->handleAction($action);
        return $action;
    }

    /**
     * @param Mage_Sales_Model_Order $order
     *
     * @return RedirectAction
     *
     * @since 1.0.0
     */
    protected function updateOrder(Mage_Sales_Model_Order $order)
    {
        $this->getHelper()->destroyDeviceFingerprintId();

        $this->getHelper()->validateBasket();

        $order->sendNewOrderEmail();

        return new RedirectAction($this->getUrl('checkout/onepage/success'));
    }

    /**
     * This method is called by Wirecard servers to modify the state of an order. Notifications are generally the
     * source of truth regarding orders, meaning the `NotificationHandler` will most likely overwrite things
     * by the `ReturnHandler`.
     *
     * @return \Wirecard\PaymentSdk\Response\Response
     * @throws \WirecardEE\PaymentGateway\Exception\UnknownPaymentException
     *
     * @since 1.0.0
     */
    public function notifyAction()
    {
        $notificationHandler = new NotificationHandler(
            $this->getHelper()->getTransactionManager(),
            $this->getHelper()->getLogger()
        );
        $request             = $this->getRequest();
        $payment             = (new PaymentFactory())->create($request->getParam('method'));
        $notification        = null;

        try {
            $backendService = new BackendService(
                $payment->getTransactionConfig(Mage::app()->getLocale()->getCurrency())
            );
            $notification   = $backendService->handleNotification($request->getRawBody());

            $notifyTransaction = $notificationHandler->handleResponse($notification, $backendService);
            if ($notifyTransaction) {
                $this->sendNotificationMail($notification, $notifyTransaction);
            }
        } catch (\Exception $e) {
            $this->logException('Notification handling failed', $e);
        }
        return $notification;
    }

    /**
     * @param SuccessResponse                            $notification
     * @param Mage_Sales_Model_Order_Payment_Transaction $notifyTransaction
     *
     * @throws Zend_Mail_Exception
     *
     * @since 1.0.0
     */
    private function sendNotificationMail(
        SuccessResponse $notification,
        \Mage_Sales_Model_Order_Payment_Transaction $notifyTransaction
    ) {
        $notificationMail = new MerchantNotificationMail($this->getHelper());
        $mail             = $notificationMail->create(
            \Mage::getStoreConfig('wirecardee_paymentgateway/settings/notify_mail'),
            $notification,
            $notifyTransaction
        );
        if ($mail) {
            $mail->send();
        }
    }

    /**
     * In case a payment has been canceled this action is called. It basically restores the basket (if it has not been
     * cancelled!) and redirects the user to the checkout.
     *
     * @return bool
     *
     * @throws Exception
     *
     * @since 1.0.0
     */
    public function cancelAction()
    {
        $success = $this->cancelOrderAndRestoreBasket();
        $this->_redirect('checkout/onepage');
        return $success;
    }

    /**
     * @param Action $action
     *
     * @return Mage_Core_Controller_Varien_Action
     * @throws UnknownActionException
     *
     * @since 1.0.0
     */
    protected function handleAction(Action $action)
    {
        if ($action instanceof RedirectAction) {
            return $this->_redirectUrl($action->getUrl());
        }
        if ($action instanceof ViewAction) {
            return $this->render($action);
        }
        if ($action instanceof ErrorAction) {
            $this->cancelOrderAndRestoreBasket();
            $this->getCheckoutSession()->setData(
                'error_message',
                Mage::helper('catalog')->__($action->getMessage())
            );
            return $this->_redirect('checkout/onepage/failure');
        }
        throw new UnknownActionException(get_class($action));
    }

    /**
     * @param ViewAction $action
     *
     * @return Mage_Core_Controller_Varien_Action
     *
     * @since 1.0.0
     */
    private function render(ViewAction $action)
    {
        $this->loadLayout();

        /** @var Mage_Core_Block_Template $root */
        $root = $this->getLayout()->getBlock('root');
        $root->setTemplate('page/1column.phtml');

        $block = $this->getLayout()->createBlock($action->getBlockName());
        $block->setData($action->getAssignments());

        $this->getLayout()->getBlock('content')->append($block);

        return $this->renderLayout();
    }

    /**
     * @return bool
     * @throws Exception
     */
    private function cancelOrderAndRestoreBasket()
    {
        $order       = $this->getCheckoutSession()->getLastRealOrder();
        $cancelState = Mage_Sales_Model_Order::STATE_CANCELED;
        if (! $order || $order->getState() === $cancelState) {
            return false;
        }

        $order->setState($cancelState, $cancelState, $this->getHelper()->__('order_cancelled'));

        /** @var Mage_Sales_Model_Quote $quote */
        $quote = Mage::getModel('sales/quote')->load($order->getQuoteId());

        if ($quote->getId()) {
            $quote->setIsActive(1)
                  ->setReservedOrderId(null)
                  ->save();
            $this->getCheckoutSession()->replaceQuote($quote);
        }

        if (Mage::getStoreConfig('wirecardee_paymentgateway/settings/delete_cancelled_orders')) {
            Mage::register('isSecureArea', true);
            $order->delete();
            Mage::unregister('isSecureArea');
        }

        return true;
    }

    /**
     * @param           $message
     * @param Exception $exception
     *
     * @since 1.0.0
     */
    private function logException($message, \Exception $exception)
    {
        $this->getHelper()->getLogger()->error(
            $message . ' - ' . get_class($exception) . ': ' . $exception->getMessage()
        );
    }

    /**
     * @return false|Mage_Core_Model_Abstract|Mage_Sales_Model_Order_Payment_Transaction
     *
     * @since 1.0.0
     */
    protected function getOrderPaymentTransaction()
    {
        return Mage::getModel('sales/order_payment_transaction');
    }

    /**
     * @param       $route
     * @param array $params
     *
     * @return string
     *
     * @since 1.0.0
     */
    protected function getUrl($route, $params = [])
    {
        return Mage::getUrl($route, $params);
    }

    /**
     * @return Mage_Core_Helper_Abstract|WirecardEE_PaymentGateway_Helper_Data
     *
     * @since 1.0.0
     */
    protected function getHelper()
    {
        return Mage::helper('paymentgateway');
    }

    /**
     * @return Mage_Checkout_Model_Session|Mage_Core_Model_Abstract
     *
     * @since 1.0.0
     */
    protected function getCheckoutSession()
    {
        return Mage::getSingleton('checkout/session');
    }
}