<?php

namespace craft\commerce\wallee\models;

use Craft;
use craft\base\Model;

/**
 * Settings model
 */
class Settings extends Model
{

    public $orderStatus = [];

    protected function defineRules(): array
    {
        return array_merge(parent::defineRules(), [
            // ...
        ]);
    }
}
