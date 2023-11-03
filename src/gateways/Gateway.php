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

use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Model\TransactionState;

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
            $metadata = $walleeTransaction->getMetaData();

            $orderId = (int)$metadata["orderId"];
            $order = Order::findOne($orderId);

            if (empty($order)) {
                Craft::warning('Order not found: '.json_encode($data), 'craft-commerce-wallee');
                $response->data = 'Warning: Order not found.';
                return $response;
            }

            $walleeState = $walleeTransaction->getState();

            Craft::info('Wallee state: '.$walleeState, 'craft-commerce-wallee');

            //map transaction state to order state
            $settings = Craft::$app->getPlugins()->getPlugin('commerce-wallee')->getSettings();
            $orderStatus = explode(":", $settings['orderStatus'][strtolower($walleeState)]['orderStatus']);

            if(count($orderStatus)){
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

        $this->order = $transaction->order;
        
        $walleeTransaction = CommerceWallee::getInstance()->getWalleeService()->getTransaction($transaction->reference, $this->order);
        
        Craft::info('Refund transaction: '.$walleeTransaction->getId(), 'craft-commerce-wallee');

        $amount = $walleeTransaction->getAuthorizationAmount();

        $this->initialize();

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
}