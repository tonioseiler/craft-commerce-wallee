<?php


namespace craft\commerce\wallee\gateways;

use Craft;
use craft\commerce\base\Gateway as BaseGateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\base\ShippingMethod;
use craft\commerce\elements\Order;
use craft\commerce\errors\PaymentException;
use craft\commerce\helpers\Currency;
use craft\commerce\models\Address;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\wallee\CommerceWallee;
use craft\commerce\wallee\CommerceWalleeBundle;
use craft\commerce\services\Transactions;
use craft\commerce\Plugin;
use craft\commerce\Plugin as Commerce;
use craft\helpers\App;
use craft\helpers\ArrayHelper;
use craft\helpers\Json;
use craft\helpers\UrlHelper;
use craft\web\Response;
use craft\web\Response as WebResponse;
use craft\commerce\wallee\responses\CheckoutResponse;
use craft\web\View;
use Throwable;
use Twig\Error\LoaderError;
use Twig\Error\RuntimeError;
use Twig\Error\SyntaxError;
use yii\base\Exception;
use yii\base\InvalidConfigException;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\wallee\controllers;
use craft\commerce\wallee\controllers\DefaultController;

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
        dd("completeAuthorize");
        $request = $this->_prepareOffsiteTransactionConfirmationRequest($transaction);
        $completeRequest = $this->prepareCompleteAuthorizeRequest($request);

        return $this->performRequest($completeRequest, $transaction);
    }

    public function completePurchase(Transaction $transaction): RequestResponseInterface
    {
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
        if ($data) {

            $params = Craft::$app->getRequest()->getQueryParams();
            $options = Commerce::getInstance()->getGateways()->getGatewayById($params['gateway']);
            $client = new \Wallee\Sdk\ApiClient($options->userId, $options->apiSecretKey);
            $transactionService = new \Wallee\Sdk\Service\TransactionService($client);
            $transactionWallee = $transactionService->read($data['spaceId'], $data['entityId']);
            $metadata = $transactionWallee->getMetaData();

            $orderId = (int)$metadata["orderId"];
            $order = Order::findOrFail($orderId);

            $state = strtolower($transactionWallee->getState());
            $settings = Craft::$app->getPlugins()->getPlugin('commerce-wallee')->getSettings();
            $orderStatus = explode(":", $settings['orderStatus'][$state]['orderStatus']);

            if(count($orderStatus)){
                $order->orderStatusId = $orderStatus[1];
                $order->dateUpdated = new \DateTime();
                Craft::$app->getElements()->saveElement($order);
            }

            $response->data = $order->number;

            try {
                $transaction = Commerce::getInstance()->getTransactions()->createTransaction($order);
                $transaction->type = TransactionRecord::TYPE_PURCHASE;
                $transaction->status = TransactionRecord::STATUS_SUCCESS;
                $transaction->response = $data;
                if(!Commerce::getInstance()->getTransactions()->saveTransaction($transaction, true)){
                    $response->data = "not saved transaction";
                }else{
                    $response->data = json_encode($metadata) . $transactionWallee->getState();
                }
            }catch (\Exception $e){
                $response->data = $e->getMessage();
            }

        }


        return $response;
    }

    public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
    {
        dd("authorize");
        // TODO: Implement authorize() method.
    }

    public function capture(Transaction $transaction, string $reference): RequestResponseInterface
    {
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