<?php
/**
 * Copyright 2015 SIGNIFYD Inc. All rights reserved.
 * See LICENSE.txt for license details.
 */

namespace Signifyd\Connect\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Model\Order;
use Signifyd\Connect\Helper\PurchaseHelper;
use Signifyd\Connect\Logger\Logger;
use Signifyd\Connect\Helper\ConfigHelper;
use Signifyd\Connect\Model\Casedata;
use Signifyd\Connect\Model\CasedataFactory;
use Signifyd\Connect\Model\ResourceModel\Casedata as CasedataResourceModel;
use Magento\Sales\Model\ResourceModel\Order as OrderResourceModel;
use Magento\Sales\Model\OrderFactory;

/**
 * Observer for purchase event. Sends order data to Signifyd service
 */
class Purchase implements ObserverInterface
{
    /**
     * @var Logger;
     */
    protected $logger;

    /**
     * @var PurchaseHelper
     */
    protected $purchaseHelper;

    /**
     * @var ConfigHelper
     */
    protected $configHelper;

    /**
     * @var CasedataFactory
     */
    protected $casedataFactory;

    /**
     * @var CasedataResourceModel
     */
    protected $casedataResourceModel;

    /**
     * @var OrderResourceModel
     */
    protected $orderResourceModel;

    /**
     * @var OrderFactory
     */
    protected $orderFactory;

    /**
     * Methods that should wait e-mail sent to hold order
     * @var array
     */
    protected $specialMethods = ['payflow_express'];

    /**
     * List of methods that uses a different event for triggering case creation
     * This is useful when it's needed case creation to be delayed to wait for other processes like data return from
     * payment method
     *
     * @var array
     */
    protected $ownEventsMethods = ['authorizenet_directpost'];

    /**
     * Restricted payment methods
     * @var array
     * @deprecated
     *
     * Restricted payment methods are no longer managed in this method.
     * Please add customized restricted payment methods to core_config_data table as below.
     *
     * INSERT INTO core_config_data(path, value) VALUES (
     * 'signifyd/general/restrict_payment_methods',
     * 'checkmo,cashondelivery,banktransfer,purchaseorder'
     * );
     */
    protected $restrictedMethods;

    /**
     * Purchase constructor.
     * @param Logger $logger
     * @param PurchaseHelper $purchaseHelper
     * @param ConfigHelper $configHelper
     * @param CasedataFactory $casedataFactory
     * @param CasedataResourceModel $casedataResourceModel
     * @param OrderResourceModel $orderResourceModel
     * @param OrderFactory $orderFactory
     */
    public function __construct(
        Logger $logger,
        PurchaseHelper $purchaseHelper,
        ConfigHelper $configHelper,
        CasedataFactory $casedataFactory,
        CasedataResourceModel $casedataResourceModel,
        OrderResourceModel $orderResourceModel,
        OrderFactory $orderFactory
    ) {
        $this->logger = $logger;
        $this->purchaseHelper = $purchaseHelper;
        $this->configHelper = $configHelper;
        $this->casedataFactory = $casedataFactory;
        $this->casedataResourceModel = $casedataResourceModel;
        $this->orderResourceModel = $orderResourceModel;
        $this->orderFactory = $orderFactory;
    }

    /**
     * @param Observer $observer
     * @param bool $checkOwnEventsMethods
     */
    public function execute(Observer $observer, $checkOwnEventsMethods = true)
    {
        try {
            $this->logger->info('Processing Signifyd event ' . $observer->getEvent()->getName());

            /** @var $order Order */
            $order = $observer->getEvent()->getOrder();

            if (!is_object($order)) {
                return;
            }

            if ($this->configHelper->isEnabled($order) == false) {
                return;
            }

            // Check if a payment is available for this order yet
            if ($order->getState() == \Magento\Sales\Model\Order::STATE_PENDING_PAYMENT) {
                return;
            }

            $paymentMethod = $order->getPayment()->getMethod();
            $state = $order->getState();
            $incrementId = $order->getIncrementId();

            $checkOwnEventsMethodsEvent = $observer->getEvent()->getCheckOwnEventsMethods();

            if ($checkOwnEventsMethodsEvent !== null) {
                $checkOwnEventsMethods = $checkOwnEventsMethodsEvent;
            }

            if ($checkOwnEventsMethods && in_array($paymentMethod, $this->ownEventsMethods)) {
                return;
            }

            if ($this->isRestricted($paymentMethod, $state, 'create')) {
                $message = 'Case creation for order ' . $incrementId . ' with state ' . $state . ' is restricted';
                $this->logger->debug($message, ['entity' => $order]);
                return;
            }

            /** @var $case \Signifyd\Connect\Model\Casedata */
            $case = $this->casedataFactory->create();
            $this->casedataResourceModel->load($case, $order->getIncrementId());

            // Check if case already exists for this order
            if ($case->isEmpty() == false) {
                return;
            }

            $message = "Creating case for order {$incrementId}, state {$state}, payment method {$paymentMethod}";
            $this->logger->debug($message, ['entity' => $order]);

            /** @var $case \Signifyd\Connect\Model\Casedata */
            $case = $this->casedataFactory->create();
            $case->setId($order->getIncrementId());
            $case->setSignifydStatus("PENDING");
            $case->setCreated(strftime('%Y-%m-%d %H:%M:%S', time()));
            $case->setUpdated();
            $case->setEntriesText("");

            // Stop case sending if order has an async payment method
            if (in_array($paymentMethod, $this->getAsyncPaymentMethodsConfig())) {
                $case->setMagentoStatus(Casedata::ASYNC_WAIT);

                try {
                    $this->casedataResourceModel->save($case);
                    $this->logger->debug(
                        'Case for order:#' . $incrementId . ' was not sent because of an async payment method',
                        ['entity' => $case]
                    );

                    // Initial hold order
                    $this->holdOrder($order);
                    $this->orderResourceModel->save($order);
                } catch (\Exception $ex) {
                    $this->logger->error($ex->__toString());
                }

                return;
            }

            $orderData = $this->purchaseHelper->processOrderData($order);
            $investigationId = $this->purchaseHelper->postCaseToSignifyd($orderData, $order);

            // Initial hold order
            $this->holdOrder($order);

            if ($investigationId) {
                $case->setCode($investigationId);
                $case->setMagentoStatus(Casedata::IN_REVIEW_STATUS);
                $case->setUpdated();
            }

            $this->casedataResourceModel->save($case);
            $this->orderResourceModel->save($order);
        } catch (\Exception $ex) {
            $context = [];

            if (isset($order) && $order instanceof Order) {
                $context['entity'] = $order;
            }

            $this->logger->error($ex->getMessage(), $context);
        }
    }

    /**
     * Used on UpgradeScheme starting on version 3.2.0 for backward compatibility
     *
     * Do not remove this method
     *
     * Tries to get restricted payment methods from class property
     *
     * @return array
     * @deprecated
     */
    public function getOldRestrictMethods()
    {
        if (isset($this->restrictedMethods) && empty($this->restrictedMethods) == false) {
            return $this->restrictedMethods;
        } else {
            return false;
        }
    }

    /**
     * Get async payment methods from store configs
     *
     * @return array|mixed
     */
    public function getAsyncPaymentMethodsConfig()
    {
        $asyncPaymentMethods = $this->configHelper->getConfigData('signifyd/general/async_payment_methods');
        $asyncPaymentMethods = explode(',', $asyncPaymentMethods);
        $asyncPaymentMethods = array_map('trim', $asyncPaymentMethods);

        return $asyncPaymentMethods;
    }

    /**
     * Get restricted payment methods from store configs
     *
     * @return array|mixed
     */
    public function getRestrictedPaymentMethodsConfig()
    {
        $restrictedPaymentMethods = $this->configHelper->getConfigData('signifyd/general/restrict_payment_methods');
        $restrictedPaymentMethods = explode(',', $restrictedPaymentMethods);
        $restrictedPaymentMethods = array_map('trim', $restrictedPaymentMethods);

        return $restrictedPaymentMethods;
    }

    /**
     * Check if there is any restrictions by payment method or state
     *
     * @param $method
     * @param null $state
     * @return bool
     */
    public function isRestricted($paymentMethodCode, $state, $action = 'default')
    {
        if (empty($state)) {
            return true;
        }

        $restrictedPaymentMethods = $this->getRestrictedPaymentMethodsConfig();

        if (in_array($paymentMethodCode, $restrictedPaymentMethods)) {
            return true;
        }

        return $this->isStateRestricted($state, $action);
    }

    /**
     * Check if state is restricted
     *
     * @param $state
     * @param string $action
     * @return bool
     */
    public function isStateRestricted($state, $action = 'default')
    {
        $restrictedStates = $this->configHelper->getConfigData("signifyd/general/restrict_states_{$action}");
        $restrictedStates = explode(',', $restrictedStates);
        $restrictedStates = array_map('trim', $restrictedStates);
        $restrictedStates = array_filter($restrictedStates);

        if (empty($restrictedStates) && $action != 'default') {
            return $this->isStateRestricted($state, 'default');
        }

        if (in_array($state, $restrictedStates)) {
            return true;
        }

        return false;
    }

    /**
     * @param $order
     * @return bool
     */
    public function holdOrder(\Magento\Sales\Model\Order $order)
    {
        /** @var $case \Signifyd\Connect\Model\Casedata */
        $case = $this->casedataFactory->create();
        $this->casedataResourceModel->load($case, $order->getIncrementId());

        $positiveAction = $case->getPositiveAction();
        $negativeAction = $case->getNegativeAction();

        if (($positiveAction != 'nothing' || $negativeAction != 'nothing')) {
            if (!$order->canHold()) {
                $notHoldableStates = [
                    Order::STATE_CANCELED,
                    Order::STATE_PAYMENT_REVIEW,
                    Order::STATE_COMPLETE,
                    Order::STATE_CLOSED,
                    Order::STATE_HOLDED
                ];

                if (in_array($order->getState(), $notHoldableStates)) {
                    $reason = "order is on {$order->getState()} state";
                } elseif ($order->getActionFlag(Order::ACTION_FLAG_HOLD) === false) {
                    $reason = "order action flag is set to do not hold";
                } else {
                    $reason = "unknown reason";
                }

                $message = "Order {$order->getIncrementId()} can not be held because {$reason}";
                $this->logger->debug($message, ['entity' => $order]);

                return false;
            }

            if (in_array($order->getPayment()->getMethod(), $this->specialMethods)) {
                if (!$order->getEmailSent()) {
                    return false;
                }
            }

            $this->logger->debug(
                'Purchase Observer Order Hold: No: ' . $order->getIncrementId(),
                ['entity' => $order]
            );

            $order->hold();
            $order->addCommentToStatusHistory("Signifyd: after order place");
        }

        return true;
    }
}
