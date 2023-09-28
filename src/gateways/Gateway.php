<?php


namespace craft\commerce\wallee\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
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

class Gateway extends BaseGateway
{
    
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
            $this->order = Commerce::getInstance()->getCarts()->getCart();
        }

        $this->options = Commerce::getInstance()->getGateways()->getGatewayById($this->order->gatewayId);
        if (property_exists($this->options, 'userId')) {
            $this->client = new \Wallee\Sdk\ApiClient($this->options->userId, $this->options->apiSecretKey);
            $transactionPayload = $this->createOrder();
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
        Craft::info('completeAuthorize', 'craft-commerce-wallee');
        dd("completeAuthorize");
        $request = $this->_prepareOffsiteTransactionConfirmationRequest($transaction);
        $completeRequest = $this->prepareCompleteAuthorizeRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
        Craft::info('completePurchase', 'craft-commerce-wallee');
        dd("completePurchase");
        $request = $this->_prepareOffsiteTransactionConfirmationRequest($transaction);
        $completeRequest = $this->prepareCompletePurchaseRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    public function getPaymentFormHtml(array $params): ?string
    {
        $this->params = $params;

        $this->initialize();

        $view = Craft::$app->getView();
        
        $previousMode = $view->getTemplateMode();
        $view->setTemplateMode(View::TEMPLATE_MODE_CP);
        
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

        return $html;
    }

    
    private function createOrder(){
        $lineItems = [];
        foreach ($this->order->lineItems as $item) {
            $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
            $lineItem->setName($item->getDescription());
            $lineItem->setUniqueId($item->id);
            $lineItem->setSku($item->getSku());
            $lineItem->setQuantity($item->qty);
            $lineItem->setAmountIncludingTax(round($item->getSubtotal(), 2));
            $lineItem->setType(\Wallee\Sdk\Model\LineItemType::PRODUCT);
            $lineItems[] = $lineItem;
        }
        if(!is_null($this->order->totalDiscount)) {
            $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
            $lineItem->setName('Discount');
            $lineItem->setUniqueId(uniqid());
            $lineItem->setQuantity(1);
            $lineItem->setAmountIncludingTax(round($this->order->totalDiscount, 2));
            $lineItem->setType(\Wallee\Sdk\Model\LineItemType::DISCOUNT);
            $lineItems[] = $lineItem;
        }

        if(!is_null($this->order->totalShippingCost)) {
            $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
            $lineItem->setName('Shipping');
            $lineItem->setUniqueId(uniqid());
            $lineItem->setQuantity(1);
            $lineItem->setAmountIncludingTax(round($this->order->totalShippingCost, 2));
            $lineItem->setType(\Wallee\Sdk\Model\LineItemType::SHIPPING);
            $lineItems[] = $lineItem;
        }

        $transactionPayload = new \Wallee\Sdk\Model\TransactionCreate();
        $transactionPayload->setCurrency($this->order->paymentCurrency);
        $transactionPayload->setMetaData(['orderId' => $this->order->id]);
        $transactionPayload->setLineItems($lineItems);
        $transactionPayload->setAutoConfirmationEnabled(true);

        $failedUrl = $this->params['cancelUrl'] ?? "/";
        $transactionPayload->setFailedUrl(UrlHelper::actionUrl('commerce-wallee/default/failed', ['cancelUrl' => $failedUrl]));

        $successUrl = $this->params['successUrl'] ?? "/";
        $transactionPayload->setSuccessUrl(UrlHelper::actionUrl('commerce-wallee/default/complete', ['successUrl' => $successUrl, 'orderId' => $this->order->id]));

        return $transactionPayload;
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
            $client = new \Wallee\Sdk\ApiClient($options->userId, $options->apiSecretKey);
            $transactionService = new \Wallee\Sdk\Service\TransactionService($client);
            $walleeTransaction = $transactionService->read($data['spaceId'], $data['entityId']);
            $metadata = $walleeTransaction->getMetaData();

            $orderId = (int)$metadata["orderId"];
            $order = Order::findOne($orderId);

            if (empty($order))
                throw new NotFoundHttpException('Order not found.');

            $walleeState = $walleeTransaction->getState();

            Craft::info('Walle state: '.$walleeState, 'craft-commerce-wallee');

            //map transaction state to order state
            $settings = Craft::$app->getPlugins()->getPlugin('commerce-wallee')->getSettings();
            $orderStatus = explode(":", $settings['orderStatus'][strtolower($walleeState)]['orderStatus']);

            if(count($orderStatus)){
                $order->orderStatusId = $orderStatus[1];
                $order->dateUpdated = new \DateTime();
                Craft::$app->getElements()->saveElement($order);
            }

            $response->data = $order->number;

            //record transaction
            try {
                $transaction = Commerce::getInstance()->getTransactions()->createTransaction($order);

                /* Map the transaction states

                Wallee states (from Wallee\Sdk\Model\TransactionState)
                //CREATE = 'CREATE';
                //PENDING = 'PENDING';
                //CONFIRMED = 'CONFIRMED';
                //PROCESSING = 'PROCESSING';
                //FAILED = 'FAILED';
                //AUTHORIZED = 'AUTHORIZED';
                //VOIDED = 'VOIDED';
                //COMPLETED = 'COMPLETED';
                //FULFILL = 'FULFILL';
                //DECLINE = 'DECLINE';

                Commerce states (from craft\commerce\model\TransactionRecord)
                //STATUS_PENDING = 'pending';
                //STATUS_REDIRECT = 'redirect';
                //STATUS_PROCESSING = 'processing';
                //STATUS_SUCCESS = 'success';
                //STATUS_FAILED = 'failed';

                */

                /* MAP the transaction types

                Wallee types ??? determied from state?

                Commerce Types (from craft\commerce\records\TransactionRecord)
                // TYPE_AUTHORIZE = 'authorize';
                // TYPE_CAPTURE = 'capture';
                // TYPE_PURCHASE = 'purchase';
                // TYPE_REFUND = 'refund';
                */

                $state = TransactionRecord::STATUS_PENDING;
                $type = TransactionRecord::TYPE_CAPTURE;

                if ($walleeState == TransactionState::CREATE) {
                    $state = TransactionRecord::STATUS_PENDING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::PENDING) {
                    $state = TransactionRecord::STATUS_PENDING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::CONFIRMED) {
                    $state = TransactionRecord::STATUS_PROCESSING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::PROCESSING) {
                    $state = TransactionRecord::STATUS_PROCESSING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::FAILED) {
                    $state = TransactionRecord::STATUS_FAILED;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::AUTHORIZED) {
                    $state = TransactionRecord::STATUS_PROCESSING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::VOIDED) {
                    $state = TransactionRecord::STATUS_FAILED;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::COMPLETED) {
                    $state = TransactionRecord::STATUS_PROCESSING;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::FULFILL) {
                    $state = TransactionRecord::STATUS_SUCCESS;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }
                elseif ($walleeState == TransactionState::DECLINE) {
                    $state = TransactionRecord::STATUS_FAILED;
                    $type = TransactionRecord::TYPE_CAPTURE;
                }

                $transaction->status = $state;
                $transaction->type = $type;

                $transaction->response = $data;
                if(!Commerce::getInstance()->getTransactions()->saveTransaction($transaction, true)){
                    $response->data = "not saved transaction";
                }else{
                    $response->data = json_encode($metadata) . $walleeTransaction->getState();
                }
            }catch (\Exception $e){
                $response->data = $e->getMessage();
            }

        }
        return $response;
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        Craft::info('Authorize', 'craft-commerce-wallee');
        dd("authorize");
        // TODO: Implement authorize() method.
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
        Craft::info('Capture', 'craft-commerce-wallee');
        dd("capture");
        // TODO: Implement capture() method.
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

        Craft::info('Refund transaction: '.$transaction->getId(), 'craft-commerce-wallee');

        $this->order = $transaction->order;

        $transaction = CommerceWallee::getInstance()->getWalleeService()->getTransaction($transaction->reference, $this->order);

        $amount = $transaction->getAuthorizationAmount();


        $this->initialize();

        //create a wallee transaction to refund
        $refund = new \Wallee\Sdk\Model\RefundCreate();
        $refund->setAmount($amount);
        $refund->setTransaction($transaction->getId());
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
}