<?php

namespace craft\commerce\wallee\plugin;

use craft\commerce\wallee\services\CommerceWalleeService;


trait Services{

    public function getWalleeService(): CommerceWalleeService
    {
        return $this->get('commerceWalleeService');
    }

}