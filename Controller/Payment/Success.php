<?php

namespace Meetanshi\WorldpayHosted\Controller\Payment;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\Order\Invoice;
use Magento\Sales\Model\Order\Payment\Transaction;
use Meetanshi\WorldpayHosted\Controller\Payment as WorldpayHostedPayment;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;

/**
 * Class Success
 * @package Meetanshi\WorldpayHosted\Controller\Payment
 */
class Success extends WorldpayHostedPayment implements CsrfAwareActionInterface
{
    /**
     * @return \Magento\Framework\App\ResponseInterface|\Magento\Framework\Controller\ResultInterface
     * @throws \Magento\Framework\Exception\LocalizedException
     * @throws \Magento\Framework\Exception\MailException
     */
    public function execute()
    {
        $params = $this->getRequest()->getParams();
        $this->helper->logger("Success from worldpay", $params);
        if (is_array($params) && !empty($params)) {
            $rawAuthCode = $params['rawAuthCode'];
            $orderId = explode("-", $params['cartId']);
            $order = $this->orderFactory->create()->loadByIncrementId($orderId['1']);
            $payment = $order->getPayment();
            if ($rawAuthCode == 'A') {
                if($order->isCanceled()) {
                    $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);
                    order->save();
                    $orderItems = $order->getAllItems();
                    foreach ($orderItems as $item) {
                        $item->setData("qty_canceled",0)->save();
                    }
                }

                $cardType = $params['cardType'];
                $transactionID = $params['transId'];
                $rawAuthMessage = $params['rawAuthMessage'];
                $rawAuthCode = $params['rawAuthCode'];
                $transStatus = $params['transStatus'];
                $cartID = $params['cartId'];

                $payment->setTransactionId($transactionID);
                $payment->setLastTransId($transactionID);
                $payment->setAdditionalInformation('cardType', $cardType);
                $payment->setAdditionalInformation('transId', $transactionID);
                $payment->setAdditionalInformation('rawAuthMessage', $rawAuthMessage);
                $payment->setAdditionalInformation('rawAuthCode', $rawAuthCode);
                $payment->setAdditionalInformation('transStatus', $transStatus);
                $payment->setAdditionalInformation('cartId', $cartID);

                $payment->setAdditionalInformation((array)$payment->getAdditionalInformation());
                $trans = $this->transactionBuilder;
                $transaction = $trans->setPayment($payment)->setOrder($order)->setTransactionId($transactionID)->setAdditionalInformation((array)$payment->getAdditionalInformation())->setFailSafe(true)->build(Transaction::TYPE_CAPTURE);

                $payment->addTransactionCommentsToOrder($transaction, 'Transaction is approved by the bank');
                $payment->setParentTransactionId(null);

                $payment->save();

                $this->orderSender->notify($order);

                $order->setStatus(\Magento\Sales\Model\Order::STATE_PROCESSING);
                $order->setState(\Magento\Sales\Model\Order::STATE_PROCESSING);

                $order->addStatusHistoryComment(__('Transaction is approved by the bank'), Order::STATE_PROCESSING)->setIsCustomerNotified(true);

                $order->save();

                $transaction->save();

                if ($this->helper->isAutoInvoice()) {
                    if (!$order->canInvoice()) {
                        $order->addStatusHistoryComment('Sorry, Order cannot be invoiced.', false);
                    }
                    $invoice = $this->invoiceService->prepareInvoice($order);
                    if (!$invoice) {
                        $order->addStatusHistoryComment('Can\'t generate the invoice right now.', false);
                    }

                    if (!$invoice->getTotalQty()) {
                        $order->addStatusHistoryComment('Can\'t generate an invoice without products.', false);
                    }
                    $invoice->setRequestedCaptureCase(Invoice::CAPTURE_ONLINE);
                    $invoice->register();
                    $invoice->getOrder()->setCustomerNoteNotify(true);
                    $invoice->getOrder()->setIsInProcess(true);
                    $transactionSave = $this->transactionFactory->create()->addObject($invoice)->addObject($invoice->getOrder());
                    $transactionSave->save();

                    try {
                        $this->invoiceSender->send($invoice);
                    } catch (\Magento\Framework\Exception\LocalizedException $e) {
                        $order->addStatusHistoryComment('Can\'t send the invoice Email right now.', false);
                    }

                    $order->addStatusHistoryComment('Automatically Invoice Generated.', false);
                    $order->save();
                }
            }

            if ($rawAuthCode == 'C') {
                $errorMsg = __('Transaction was not Successful. Your Order was not completed. Please try again later');

                if (array_key_exists('cardType', $params)) {
                    $cardType = $params['cardType'];
                    $payment->setAdditionalInformation('cardType', $cardType);
                }
                if (array_key_exists('transId', $params)) {
                    $transactionID = $params['transId'];
                    $payment->setAdditionalInformation('transId', $transactionID);
                }
                if (array_key_exists('rawAuthMessage', $params)) {
                    $rawAuthMessage = $params['rawAuthMessage'];
                    $payment->setAdditionalInformation('rawAuthMessage', $rawAuthMessage);
                }
                if (array_key_exists('rawAuthCode', $params)) {
                    $rawAuthCode = $params['rawAuthCode'];
                    $payment->setAdditionalInformation('rawAuthCode', $rawAuthCode);
                }
                if (array_key_exists('transStatus', $params)) {
                    $transStatus = $params['transStatus'];
                    $payment->setAdditionalInformation('transStatus', $transStatus);
                }

                $payment->setAdditionalInformation((array)$payment->getAdditionalInformation());

                $order->cancel()->setState(\Magento\Sales\Model\Order::STATE_CANCELED, true, 'Gateway has declined the payment.');
                $payment->setStatus('DECLINED');
                $payment->setShouldCloseParentTransaction(1)->setIsTransactionClosed(1);
                $payment->save();
                $order->setStatus(\Magento\Sales\Model\Order::STATE_CANCELED);
                $order->addStatusToHistory($order->getStatus(), $errorMsg);
                $this->messageManager->addErrorMessage($errorMsg);
                $this->checkoutSession->restoreQuote();
                $order->save();
            }
        }
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        return true;
    }
}
