<?php


namespace craft\commerce\wallee\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\elements\Order;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\wallee\CommerceWallee;
use craft\commerce\wallee\CommerceWalleeBundle;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Response;
use craft\web\Response as WebResponse;
use craft\commerce\wallee\responses\CheckoutResponse;
use craft\web\View;
use yii\web\NotFoundHttpException;
use craft\commerce\records\Transaction as TransactionRecord;

use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\TransactionState;

class Gateway extends BaseGateway
{

    const STATUS_CONFIRMED = 'CONFIRMED';
    const STATUS_PROCESSING = 'PROCESSING';
    const STATUS_AUTHORIZED = 'AUTHORIZED';
    const STATUS_COMPLETED = 'COMPLETED';
    const STATUS_FULFILL = 'FULFILL';
    const STATUS_PENDING = 'PENDING';
    const STATUS_DECLINE = 'DECLINE';
    const STATUS_FAILED = 'FAILED';
    const STATUS_VOIDED = 'VOIDED';

    /**
     * @var string
     */
    public $integrationMode;

    /**
     * @var integer
     */
    public $spaceId;

    /**
     * @var integer
     */
    public $userId;

    /**
     * @var string
     */
    public $apiSecretKey;

    private $client;

    private $options;

    private $order;

    private $transaction;

    private $params;


    public function __construct()
    {

    }

    private function initialize(){
        if($this->order == null){
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $orderId = Craft::$app->getRequest()->getBodyParam('orderId');
                $this->order = Commerce::getInstance()->getOrders()->getOrderById($orderId);
            }else{
                $this->order = Commerce::getInstance()->getCarts()->getCart();
            }
        }

        $this->options = Commerce::getInstance()->getGateways()->getGatewayById($this->order->gatewayId);
        if (property_exists($this->options, 'userId')) {
            $this->client = new ApiClient($this->options->userId, $this->options->apiSecretKey);

            $successUrl = $this->params['successUrl'] ?? "/";
            $failedUrl = $this->params['cancelUrl'] ?? "/";

            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $successUrl = $this->order->getCpEditUrl();
                $failedUrl = $this->order->getCpEditUrl();
            }

            $transactionPayload = CommerceWallee::getInstance()->getWalleeService()->createWalleeOrder($this->order, $successUrl, $failedUrl);
            $this->transaction = $this->client->getTransactionService()->create($this->options->spaceId, $transactionPayload);
        }
    }

    public static function displayName(): string
    {
        return Craft::t('commerce-wallee', 'Wallee');
    }


    public function getSettingsHtml(): ?string
    {
        return Craft::$app->getView()->renderTemplate('commerce-wallee/gatewaySettings/gatewaySettings', ['gateway' => $this]);
    }


    public function completeAuthorize(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        $this->params = $params;

        $this->initialize();

        $view = Craft::$app->getView();

        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);

        $html = '';

        if (property_exists($this->options, 'integrationMode')) {
            if (Craft::$app->getRequest()->getIsCpRequest()) {
                $this->options->integrationMode = 'iframe';
            }
            switch ($this->options->integrationMode) {
                case 'lightbox':
                    $view->registerJsFile($this->getJavascriptUrl($this->options->integrationMode));
                    break;
                case 'iframe':
                    $view->registerJsFile($this->getJavascriptUrl($this->options->integrationMode));
                    $params['paymentMethods'] = $this->fetchPaymentMethods();
                    break;
                case 'page':
                    Craft::$app->getResponse()->redirect($this->getPageUrl());
                    break;
                default:
                    break;
            }

            $view->registerAssetBundle(CommerceWalleeBundle::class);

            $html = Craft::$app->getView()->renderTemplate('commerce-wallee/_components/gateways/_' . $this->options->integrationMode, $params);
            $view->setTemplateMode($previousMode);
        }

        return $html;
    }

    private function fetchPaymentMethods(){
        return $this->client->getTransactionService()->fetchPaymentMethods($this->options->spaceId, $this->transaction->getId(), 'iframe');
    }

    /**
     * @param string $mode lightbox or iframe
     * @return string
     */
    private function getJavascriptUrl(string $mode = 'lightbox'): string{
        try {

            if($mode == 'lightbox'){
                $transactionService = new \Wallee\Sdk\Service\TransactionLightboxService($this->client);
            }else{
                $transactionService = new \Wallee\Sdk\Service\TransactionIframeService($this->client);
            }
            return $transactionService->javascriptUrl($this->options->spaceId, $this->transaction->getId());

        }catch (\Exception $e){
            return $e->getMessage();
        }
    }

    /**
     * @return string
     */
    private function getPageUrl(): string
    {
        $transactionService = new \Wallee\Sdk\Service\TransactionPaymentPageService($this->client);
        return $transactionService->paymentPageUrl($this->options->spaceId, $this->transaction->getId());
    }

    /**
     * Whether the order already holds a transaction for this wallee transaction, type and status.
     */
    private static function hasTransaction(Order $order, $reference, string $type, string $status): bool
    {
        // Read from the database, not the order's cached transactions, so the check is fresh inside the lock
        foreach (Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId($order->id) as $existing) {
            if ($existing->reference == $reference && $existing->type == $type && $existing->status == $status) {
                return true;
            }
        }

        return false;
    }

    /**
     * Records a wallee transaction on the order once, using the amount wallee actually authorized.
     * Webhooks and the success redirect call this concurrently, so it is serialised per order.
     */
    public static function recordTransaction(Order $order, \Wallee\Sdk\Model\Transaction $walleeTransaction, string $type, string $status): void
    {
        $mutex = Craft::$app->getMutex();
        $lockName = 'walleeTransaction:' . $order->id;

        if (!$mutex->acquire($lockName, 15)) {
            throw new \RuntimeException('Could not acquire lock for order ' . $order->id);
        }

        try {
            // wallee delivers a webhook per state change, so only record each state once
            if (self::hasTransaction($order, $walleeTransaction->getId(), $type, $status)) {
                Craft::info('Skipping duplicate transaction for wallee transaction '.$walleeTransaction->getId(), 'craft-commerce-wallee');
                return;
            }

            $transaction = Commerce::getInstance()->getTransactions()->createTransaction($order);
            $transaction->type = $type;
            $transaction->status = $status;
            $transaction->response = $walleeTransaction->__toString();
            $transaction->reference = $walleeTransaction->getId();
            $transaction->paymentAmount = $walleeTransaction->getAuthorizationAmount();
            $transaction->amount = $transaction->paymentAmount / $transaction->paymentRate;

            if ($transaction->paymentAmount <= 0) {
                Craft::warning('Refusing to save wallee transaction '.$walleeTransaction->getId().' with non-positive amount for order '.$order->id, 'craft-commerce-wallee');
                return;
            }

            Commerce::getInstance()->getTransactions()->saveTransaction($transaction, true);
        } finally {
            $mutex->release($lockName);
        }
    }

    public function processWebHook(): WebResponse
    {

        $response = Craft::$app->getResponse();
        $rawData = Craft::$app->getRequest()->getRawBody();

        $response->format = Response::FORMAT_RAW;
        $data = Json::decodeIfJson($rawData);

        Craft::info('processing webhook. Data: '.json_encode($data), 'craft-commerce-wallee');

        if ($data) {

            $params = Craft::$app->getRequest()->getQueryParams();
            $options = Commerce::getInstance()->getGateways()->getGatewayById($params['gateway']);
            $client = new ApiClient($options->userId, $options->apiSecretKey);
            $transactionService = new \Wallee\Sdk\Service\TransactionService($client);
            $walleeTransaction = $transactionService->read($data['spaceId'], $data['entityId']);

            $orderId = $walleeTransaction->getMerchantReference();
            $order = Order::findOne($orderId);

            if (empty($order)) {
                Craft::warning('Order not found: '.json_encode($data), 'craft-commerce-wallee');
                $response->data = 'Warning: Order not found.';
                return $response;
            }

            // Webhooks have no customer session, so a recalculation here would drop user-scoped discounts
            $order->setRecalculationMode(Order::RECALCULATION_MODE_NONE);

            $walleeState = $data['state'] ?? $walleeTransaction->getState();

            $transactionTypes = [
                self::STATUS_PENDING => [TransactionRecord::TYPE_AUTHORIZE, TransactionRecord::STATUS_PENDING],
                self::STATUS_PROCESSING => [TransactionRecord::TYPE_AUTHORIZE, TransactionRecord::STATUS_PROCESSING],
                self::STATUS_FULFILL => [TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_SUCCESS],
                self::STATUS_FAILED => [TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_FAILED],
                self::STATUS_DECLINE => [TransactionRecord::TYPE_PURCHASE, TransactionRecord::STATUS_FAILED],
            ];

            if (isset($transactionTypes[$walleeState])) {
                try {
                    [$type, $status] = $transactionTypes[$walleeState];
                    self::recordTransaction($order, $walleeTransaction, $type, $status);
                } catch (\Exception $e) {
                    Craft::error('Could not record wallee transaction for order '.$order->id.': '.$e->getMessage(), 'craft-commerce-wallee');
                }
            }


            Craft::info('Wallee state: '.$walleeState, 'craft-commerce-wallee');

            //map transaction state to order state
            $settings = Craft::$app->getPlugins()->getPlugin('commerce-wallee')->getSettings();
            $orderStatus = explode(":", $settings['orderStatus'][strtolower($walleeState)]['orderStatus']);

            if(count($orderStatus) > 1 && !empty($orderStatus[1]) && $order->orderStatusId != $orderStatus[1]){
                Craft::info('change order status: '.$order->orderStatusId.'-'.$orderStatus[1], 'craft-commerce-wallee');
                $order->orderStatusId = $orderStatus[1];
                $order->dateUpdated = new \DateTime();
                Craft::$app->getElements()->saveElement($order);
            }
            $response->data = $order->number;
        }
        return $response;
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
    }

    public function createPaymentSource(BasePaymentForm $sourceData, int $userId): PaymentSource
    {
        // TODO: Implement createPaymentSource() method.
    }

    public function deletePaymentSource($token): bool
    {
        // TODO: Implement deletePaymentSource() method.
    }

    public function getPaymentFormModel(): BasePaymentForm
    {
        return new OffsitePaymentForm();
    }

    public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        return new CheckoutResponse();
    }

    public function refund(Transaction $transaction): RequestResponseInterface
    {

        $this->order = $transaction->order;

        $walleeTransaction = CommerceWallee::getInstance()->getWalleeService()->getTransaction($transaction->reference, $this->order);

        Craft::info('Refund transaction: '.$walleeTransaction->getId(), 'craft-commerce-wallee');

        $amount = $walleeTransaction->getAuthorizationAmount();

        // Only the API client is needed; initialize() would also create a new wallee transaction for the order
        $this->options = Commerce::getInstance()->getGateways()->getGatewayById($this->order->gatewayId);
        $this->client = new ApiClient($this->options->userId, $this->options->apiSecretKey);

        //create a wallee transaction to refund
        $refund = new \Wallee\Sdk\Model\RefundCreate();
        $refund->setAmount($amount);
        $refund->setTransaction($walleeTransaction->getId());
        $refund->setType(\Wallee\Sdk\Model\RefundType::MERCHANT_INITIATED_ONLINE);
        $refund->setExternalId(uniqid());

        $refundService = new \Wallee\Sdk\Service\RefundService($this->client);
        $refund = $refundService->refund($this->options->spaceId, $refund);

        if($refund){
            return new CheckoutResponse();
        }

        return false;

    }

    public function supportsAuthorize(): bool
    {
        return false;
    }

    public function supportsCapture(): bool
    {
        return false;
    }

    public function supportsCompleteAuthorize(): bool
    {
        return false;
    }

    public function supportsCompletePurchase(): bool
    {
        return false;
    }

    public function supportsPaymentSources(): bool
    {
        return false;
    }

    public function supportsPurchase(): bool
    {
        return false;
    }

    public function supportsRefund(): bool
    {
        return true;
    }

    public function supportsPartialRefund(): bool
    {
        return false;
    }

    public function supportsWebhooks(): bool
    {
        return true;
    }

    public function availableForUseWithOrder(Order $order): bool
    {
        return $this->id == $order->gatewayId;
    }
}