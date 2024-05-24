<?php

namespace craft\commerce\wallee\base;

use Craft;
use craft\commerce\base\Plan;
use craft\commerce\base\SubscriptionGateway as BaseGateway;
use craft\commerce\base\SubscriptionResponseInterface;
use craft\commerce\elements\Subscription;
use craft\commerce\models\subscriptions\CancelSubscriptionForm;
use craft\commerce\models\subscriptions\SubscriptionForm;
use craft\commerce\models\subscriptions\SwitchPlansForm;
use craft\commerce\wallee\CommerceWallee;
use craft\elements\User;

abstract class SubscriptionGateway extends BaseGateway{

    public function getCancelSubscriptionFormHtml(Subscription $subscription): string
    {
        // TODO: Implement getCancelSubscriptionFormHtml() method.
    }

    public function getCancelSubscriptionFormModel(): CancelSubscriptionForm
    {
        // TODO: Implement getCancelSubscriptionFormModel() method.
    }

    public function getPlanSettingsHtml(array $params = []): ?string
    {
        $plansList = collect($this->getSubscriptionPlans())->mapWithKeys(function($plan) {
            return [$plan['reference'] => $plan['name']];
        })->all();

        $params = array_merge([
            'plansList' => $plansList,
        ], $params);

        return Craft::$app->getView()->renderTemplate('commerce-wallee/planSettings', $params);
    }

    public function getPlanModel(): Plan
    {
        // TODO: Implement getPlanModel() method.
    }

    public function getSubscriptionFormModel(): SubscriptionForm
    {
        // TODO: Implement getSubscriptionFormModel() method.
    }

    public function getSwitchPlansFormModel(): SwitchPlansForm
    {
        // TODO: Implement getSwitchPlansFormModel() method.
    }

    public function cancelSubscription(Subscription $subscription, CancelSubscriptionForm $parameters): SubscriptionResponseInterface
    {
        // TODO: Implement cancelSubscription() method.
    }

    public function getNextPaymentAmount(Subscription $subscription): string
    {
        // TODO: Implement getNextPaymentAmount() method.
    }

    public function getSubscriptionPayments(Subscription $subscription): array
    {
        // TODO: Implement getSubscriptionPayments() method.
    }

    public function getSubscriptionPlanByReference(string $reference): string
    {
        // TODO: Implement getSubscriptionPlanByReference() method.
    }

    public function getSubscriptionPlans(): array
    {
        $allPlans = [];

        // Get all plans from the API
        $productComponents = CommerceWallee::getInstance()->getWalleeService()->getProductComponents();
        foreach ($productComponents as $productComponent){
            $name = $productComponent['productName'] . ' - ';
            foreach ($productComponent['name'] as $key => $value){
                $name.= $value;
                break;
            }
            $allPlans[] = [
                'reference' => $productComponent['id'],
                'name' => $name . ":" . $productComponent['id']
            ];
        }

        return $allPlans;
    }

    public function subscribe(User $user, Plan $plan, SubscriptionForm $parameters): SubscriptionResponseInterface
    {
        // TODO: Implement subscribe() method.
    }

    public function switchSubscriptionPlan(Subscription $subscription, Plan $plan, SwitchPlansForm $parameters): SubscriptionResponseInterface
    {
        // TODO: Implement switchSubscriptionPlan() method.
    }

    public function supportsReactivation(): bool
    {
        // TODO: Implement supportsReactivation() method.
    }

    public function supportsPlanSwitch(): bool
    {
        // TODO: Implement supportsPlanSwitch() method.
    }

    public function getHasBillingIssues(Subscription $subscription): bool
    {
        // TODO: Implement getHasBillingIssues() method.
    }

    public function getBillingIssueDescription(Subscription $subscription): string
    {
        // TODO: Implement getBillingIssueDescription() method.
    }

    public function getBillingIssueResolveFormHtml(Subscription $subscription): string
    {
        // TODO: Implement getBillingIssueResolveFormHtml() method.
    }

}