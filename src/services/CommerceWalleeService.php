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

use craft\commerce\wallee\CommerceWallee;

use Craft;
use craft\base\Component;

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

    /**
     * This function can literally be anything you want, and you can have as many service
     * functions as you want
     *
     * From any other plugin file, call it like this:
     *
     *     CommerceWallee::$plugin->commerceWalleeService->exampleService()
     *
     * @return mixed
     */
    public function exampleService()
    {
        $result = 'something';

        return $result;
    }
}
