<?php
/**
 * Commerce wallee plugin for Craft CMS 3.x
 *
 * wallee integration for Craft Commerce 3
 *
 * @link      http://www.furbo.ch
 * @copyright Copyright (c) 2021 Furbo GmbH
 */

namespace craft\commerce\wallee\services;

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\wallee\CommerceWallee;
use craft\helpers\UrlHelper;
use craft\base\Component;

use Craft;
use Wallee\Sdk\Model\EntityQuery;
use Wallee\Sdk\Model\Transaction;
use Wallee\Sdk\Model\TransactionCreate;
use Wallee\Sdk\ApiClient;
use Wallee\Sdk\Service\TransactionService;
use Wallee\Sdk\Model\EntityQueryFilterType;
use Wallee\Sdk\Model\CriteriaOperator;
use Wallee\Sdk\Model\EntityQueryFilter;

/**
 * CommerceWalleeService Service
 *
 * All of your plugin’s business logic should go in services, including saving data,
 * retrieving data, etc. They provide APIs that your controllers, template variables,
 * and other plugins can interact with.
 *
 * https://craftcms.com/docs/plugins/services
 *
 * @author    Furbo GmbH
 * @package   CommerceWallee
 * @since     1.0.0
 */
class CommerceWalleeService extends Component
{
    // Public Methods
    // =========================================================================

    private function connect($userId, $apiSecretKey): ApiClient
    {
        return new ApiClient($userId, $apiSecretKey);
    }

    public function getTransactionByOrder(Order $order, $transactionStates): ?Transaction
    {
        $gateway = Commerce::getInstance()->getGateways()->getGatewayById($order->gatewayId);
        $client = $this->connect($gateway->userId, $gateway->apiSecretKey);

        $entityQueryFilter = new EntityQueryFilter([
           'field_name' => 'createdOn',
           'value' => $order->dateCreated->format('Y-m-d\T00:00:00'),
           'type' => EntityQueryFilterType::LEAF,
           'operator' => CriteriaOperator::GREATER_THAN_OR_EQUAL
        ]);

        $hasMoreEntities = true;
        $startingEntity = 0;

        while($hasMoreEntities){
            $query = new EntityQuery([
                'filter' => $entityQueryFilter,
                'number_of_entities' => 100,
                'starting_entity' => $startingEntity
            ]);
            $result = $client->getTransactionService()->search($gateway->spaceId, $query);

            foreach ($result as $entity) {
                if($entity->getMetaData() != null && $entity->getMetaData()['orderId'] == $order->getId() && in_array($entity->getState(), $transactionStates)) {
                    return $entity;
                }
            }

            $startingEntity += 100;
            if(count($result) < 100) {
                $hasMoreEntities = false;
            }
        }

        return null;
    }

    public function getTransaction($reference, Order $order)
    {
        $gateway = Commerce::getInstance()->getGateways()->getGatewayById($order->gatewayId);
        $client = $this->connect($gateway->userId, $gateway->apiSecretKey);

        $entityQueryFilter = new EntityQueryFilter([
            'field_name' => 'merchantReference',
            'value' => $reference,
            'type' => EntityQueryFilterType::LEAF,
            'operator' => CriteriaOperator::EQUALS
        ]);

        $query = new EntityQuery([
            'filter' => $entityQueryFilter,
            'number_of_entities' => 1,
            'starting_entity' => 0
        ]);

        $result = $client->getTransactionService()->search($gateway->spaceId, $query);
        if(count($result) > 0) {
            return $result[0];
        }

        return null;
    }

    public function refund(Order $order): bool
    {
        $transaction = $this->getTransactionById($order);

        return false;
    }

    public function createWalleeOrder(Order $order, $successUrl = '/', $failedUrl = '/'): TransactionCreate
    {

        //TODO: only add one lineitem for the whole order, otherwhise its a mess because the two data objects do not exactly match
        //so no shipping cost details etc

        $lineItems = [];
        $lineItem = new \Wallee\Sdk\Model\LineItemCreate();
        $lineItem->setName('Order total for order #'.$order->id);
        $lineItem->setUniqueId(uniqid());
        $lineItem->setSku($order->id);
        $lineItem->setQuantity(1);
        $lineItem->setAmountIncludingTax(round($order->getTotal(), 2));
        $lineItem->setType(\Wallee\Sdk\Model\LineItemType::PRODUCT);
        $lineItems[] = $lineItem;
        
        $transactionPayload = new \Wallee\Sdk\Model\TransactionCreate();
        $transactionPayload->setCurrency($order->paymentCurrency);
        $transactionPayload->setMetaData(['orderId' => $order->id]);
        $transactionPayload->setLineItems($lineItems);
        $transactionPayload->setAutoConfirmationEnabled(true);
        $transactionPayload->setMerchantReference($order->id);

        $transactionPayload->setFailedUrl(UrlHelper::actionUrl('commerce-wallee/default/failed', ['cancelUrl' => $failedUrl]));
        $transactionPayload->setSuccessUrl(UrlHelper::actionUrl('commerce-wallee/default/complete', ['successUrl' => $successUrl, 'orderId' => $order->id]));

        return $transactionPayload;
    } 
}
