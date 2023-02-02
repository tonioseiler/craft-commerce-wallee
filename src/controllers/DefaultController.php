<?php
/**
 * Commerce wallee plugin for Craft CMS 3.x
 *
 * wallee integration for Craft Commerce 3
 *
 * @link      http://www.furbo.ch
 * @copyright Copyright (c) 2021 Furbo GmbH
 */

namespace craft\commerce\wallee\controllers;

use craft\commerce\wallee\CommerceWallee;

use Craft;
use craft\web\Controller as BaseController;
use craft\commerce\Plugin as Commerce;
use yii\web\Response;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use craft\commerce\records\Transaction as TransactionRecord;

/**
 * Default Controller
 *
 * Generally speaking, controllers are the middlemen between the front end of
 * the CP/website and your plugin’s services. They contain action methods which
 * handle individual tasks.
 *
 * A common pattern used throughout Craft involves a controller action gathering
 * post data, saving it on a model, passing the model off to a service, and then
 * responding to the request appropriately depending on the service method’s response.
 *
 * Action methods begin with the prefix “action”, followed by a description of what
 * the method does (for example, actionSaveIngredient()).
 *
 * https://craftcms.com/docs/plugins/controllers
 *
 * @author    Furbo GmbH
 * @package   CommerceWallee
 * @since     1.0.0
 */
class DefaultController extends BaseController
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

    // Protected Properties
    // =========================================================================

    /**
     * @var    bool|array Allows anonymous access to this controller's actions.
     *         The actions must be in 'kebab-case'
     * @access protected
     */
    protected $allowAnonymous = ['index', 'prepare-payment', 'complete'];

    // Public Methods
    // =========================================================================

    /**
     * Handle a request going to our plugin's index action URL,
     * e.g.: actions/commerce-wallee/default
     *
     * @return mixed
     */
    public function actionIndex()
    {
        $result = 'Welcome to the DefaultController actionIndex() method';

        return $result;
    }

    /**
     * Handle a request going to our plugin's actionDoSomething URL,
     * e.g.: actions/commerce-wallee/default/prepare-payment
     *
     * @return mixed
     */
    public function actionPreparePayment(){
        try {
            $options = Commerce::getInstance()->getGateways()->getGatewayById(App::env('WALLEE_GATEWAY_ID'));

            $client = new \Wallee\Sdk\ApiClient($options->userId, $options->apiSecretKey);
            $transactionLightboxService = new \Wallee\Sdk\Service\TransactionLightboxService($client);

            $order = Commerce::getInstance()->getCarts()->getCart();
            
            $lineItems = [];
            foreach ($order->lineItems as $item) {
                $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
                $lineItem->setName($item->getDescription());
                $lineItem->setUniqueId($item->id);
                $lineItem->setSku($item->getSku());
                $lineItem->setQuantity($item->qty);
                $lineItem->setAmountIncludingTax($item->getSubtotal());
                $lineItem->setType(\Wallee\Sdk\Model\LineItemType::PRODUCT);
                $lineItems[] = $lineItem;
            }

            $transactionPayload = new \Wallee\Sdk\Model\TransactionCreate();
            $transactionPayload->setCurrency('CHF');
            $transactionPayload->setMetaData(['orderId' => $order->id]);
            $transactionPayload->setLineItems($lineItems);
            $transactionPayload->setAutoConfirmationEnabled(true);
            $transactionPayload->setFailedUrl(str_replace(":orderId", $order->id, App::env('WALLEE_PAYMENT_ERROR')));
            $transactionPayload->setSuccessUrl(str_replace(":orderId", $order->id, App::env('WALLEE_PAYMENT_SUCCESS')));

            $transaction = $client->getTransactionService()->create($options->spaceId, $transactionPayload);

            $javascriptUrl = $transactionLightboxService->javascriptUrl($options->spaceId, $transaction->getId());


            return $this->asJson([
                'success' => true,
                'data' => ['javascriptUrl' => $javascriptUrl, 'transaction_id' => $transaction->getId()]
            ]);


        }catch (\Exception $e){
            return $this->asJson([
                'success' => false,
                'message' => $e->getMessage(),
            ])->setStatusCode(422);
        }
    }

    public function actionComplete()
    {
        $order = Commerce::getInstance()->getCarts()->getCart();
        $transaction = Commerce::getInstance()->getTransactions()->createTransaction($order);
        $transaction->type = TransactionRecord::TYPE_PURCHASE;
        $transaction->status = TransactionRecord::STATUS_SUCCESS;
        if(Commerce::getInstance()->getTransactions()->saveTransaction($transaction, true)){
            Craft::$app->getResponse()->redirect(UrlHelper::siteUrl('/shop/customer/order', [ 'number' => $order->number, 'success' => 'true' ]))->send();
        }
        
        die();
    }
}
