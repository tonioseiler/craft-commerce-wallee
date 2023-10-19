<?php
/**
 * Commerce wallee plugin for Craft CMS 4.x
 *
 * wallee integration for Craft Commerce
 *
 * @link      http://www.furbo.ch
 * @copyright Copyright (c) 2021 Furbo GmbH
 */

namespace craft\commerce\wallee;

use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\wallee\models\Settings;
use craft\commerce\wallee\plugin\Services;
use craft\commerce\wallee\services\CommerceWalleeService;
use craft\commerce\wallee\variables\CommerceWalleeVariable;

use Craft;
use craft\base\Plugin;
use craft\events\RegisterElementTableAttributesEvent;
use craft\events\TemplateEvent;
use craft\services\Plugins;
use craft\events\PluginEvent;
use craft\web\twig\variables\CraftVariable;
use craft\events\RegisterComponentTypesEvent;
use craft\commerce\wallee\gateways\Gateway;
use craft\commerce\services\Gateways;

use craft\web\View;
use yii\base\Event;

use Monolog\Formatter\LineFormatter;
use Psr\Log\LogLevel;
use craft\log\MonologTarget;

/**
 * Craft plugins are very much like little applications in and of themselves. We’ve made
 * it as simple as we can, but the training wheels are off. A little prior knowledge is
 * going to be required to write a plugin.
 *
 * For the purposes of the plugin docs, we’re going to assume that you know PHP and SQL,
 * as well as some semi-advanced concepts like object-oriented programming and PHP namespaces.
 *
 * https://docs.craftcms.com/v3/extend/
 *
 * @author    Furbo GmbH
 * @package   CommerceWallee
 * @since     1.0.0
 *
 * @property  CommerceWalleeServiceService $commerceWalleeService
 */
class CommerceWallee extends Plugin
{

    use Services;

    // Static Properties
    // =========================================================================

    /**
     * Static property that is an instance of this plugin class so that it can be accessed via
     * CommerceWallee::$plugin
     *
     * @var CommerceWallee
     */
    public static $plugin;

    // Public Properties
    // =========================================================================

    /**
     * To execute your plugin’s migrations, you’ll need to increase its schema version.
     *
     * @var string
     */
    public string $schemaVersion = '1.0.0';

    /**
     * Set to `true` if the plugin should have a settings view in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSettings = true;

    /**
     * Set to `true` if the plugin should have its own section (main nav item) in the control panel.
     *
     * @var bool
     */
    public bool $hasCpSection = false;

    // Public Methods
    // =========================================================================

    public function init()
    {
        parent::init();

        $this->registerLogger();
        //register asset bundle
        Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, function (TemplateEvent $event) {
            $view = Craft::$app->getView();
            $view->registerAssetBundle(CommerceWalleeBundle::class);
        });

        self::$plugin = $this;


        // Register our variables
        Event::on(
            CraftVariable::class,
            CraftVariable::EVENT_INIT,
            function (Event $event) {
                /** @var CraftVariable $variable */
                $variable = $event->sender;
                $variable->set('commerceWallee', CommerceWalleeVariable::class);
            }
        );

        // Do something after we're installed
        Event::on(
            Plugins::class,
            Plugins::EVENT_AFTER_INSTALL_PLUGIN,
            function (PluginEvent $event) {
                if ($event->plugin === $this) {
                    // We were just installed
                }
            }
        );

        Event::on(
            Order::class,
            Order::EVENT_REGISTER_TABLE_ATTRIBUTES,
            function (RegisterElementTableAttributesEvent $event){
                $event->tableAttributes['walleeTransactionId'] = [
                    'label' => 'Transactions'
                ];
            }
        );

        // get the wallee transaction id
        Event::on(
            Order::class,
            Order::EVENT_SET_TABLE_ATTRIBUTE_HTML,
            function (Event $event){
                if ($event->attribute == 'walleeTransactionId') {
                    $order = $event->sender;
                    //get transactions from order
                    $transactions = Commerce::getInstance()->getTransactions()->getAllTransactionsByOrderId($order->id);
                    $references = [];
                    foreach ($transactions as $transaction) {
                        if($transaction->reference) {
                            $references[] = "<div>" . $transaction->reference . " - <span class='transaction-status transaction-status-{$transaction->status}'>" . $transaction->status . "</span></div>";
                        }
                    }
                    $event->html = implode('', $references);

                }
            }
        );

        Event::on(Gateways::class, Gateways::EVENT_REGISTER_GATEWAY_TYPES,  function(RegisterComponentTypesEvent $event) {
            $event->types[] = Gateway::class;
        });

        //Craft::info('commerce wallee plugin loaded', 'craft-commerce-wallee');

    }

    // Protected Methods
    // =========================================================================

    /**
     * Creates and returns the model used to store the plugin’s settings.
     *
     * @return \craft\base\Model|null
     */
    protected function createSettingsModel(): ?\craft\base\Model
    {
        return new Settings();
    }

    /**
     * Returns the rendered settings HTML, which will be inserted into the content
     * block on the settings page.
     *
     * @return string The rendered settings HTML
     */
    protected function settingsHtml(): ?string
    {
        return Craft::$app->view->renderTemplate(
            'commerce-wallee/settings',
            [
                'settings' => $this->getSettings()
            ]
        );
    }

    protected function registerLogger()
    {
        // Register a custom log target, keeping the format as simple as possible.
        Craft::getLogger()->dispatcher->targets[] = new MonologTarget([
            'name' => 'craft-commerce-wallee',
            'categories' => ['craft-commerce-wallee'],
            'level' => LogLevel::INFO,
            'logContext' => false,
            'allowLineBreaks' => false,
            'formatter' => new LineFormatter(
                format: "%datetime% %message%\n",
                dateFormat: 'Y-m-d H:i:s',
            ),
        ]);
    }


}
