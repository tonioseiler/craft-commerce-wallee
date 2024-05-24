<?php

namespace craft\commerce\wallee\models;

use craft\commerce\base\Plan as BasePlan;
use craft\commerce\base\PlanInterface;

class Plan extends BasePlan
{

    public function canSwitchFrom(PlanInterface $currentPlant): bool
    {
        /** @var BasePlan $currentPlant */
        return $currentPlant->gatewayId === $this->gatewayId;
    }
}