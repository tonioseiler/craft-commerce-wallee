<img src="resources/img/wallee-pf.png" width="100" height="100">

<h1 align="left">Wallee & Postfinance</h1>
<p>This plugin provides a <a href="https://wallee.com/">Wallee</a> or <a href="https://www.postfinance.ch/de/unternehmen/produkte/einkassieren/omnichannel/postfinance-checkout-flex.html">Postfinance Flex</a> integration for <a href="https://craftcms.com/commerce">Craft Commerce</a>.</p>

Add Wallee/Postfinance as a payment gateway in your craft commerce installation. The plugin allows configuration of many payment methods such as:
* Credit Cards
* Postfinance
* Twint
* Apple Pay
* Google Pay
* PayPal
* Klarna
* Boncard
* Luncheck
* ...

### Configurable modes:
Choose between lightbox, iframe and fullpage integration mode.

Packages availabel for Craft 3 and 4.

Wallee status changes can be mapped dynamically to craft commerce order status.

## Requirements

- Craft CMS 4.0 or later
- Craft Commerce 4.0 or later

## Installation

You can install this plugin from the Plugin Store or using Composer.

#### From the Plugin Store

Go to the Plugin Store in your project’s control panel, search for “Wallee for Craft Commerce”, and choose **Install** in the plugin’s modal window.

#### With Composer

Open your terminal and run the following commands:

```bash
# go to the project directory
cd /path/to/my-project.test

# tell Composer to load the plugin
composer require furbo/craft-commerce-wallee

# tell Craft to install the plugin
php craft install/plugin craft-commerce-wallee
```

## Wallee Setup

1. Create a new account at [Wallee](https://app-wallee.com/signup) or [Postfinance](https://www.postfinance.ch/de/unternehmen/produkte/einkassieren/omnichannel/postfinance-checkout-flex/postfinance-checkout-flex-registrieren.html)
2. Go to Space from the left menu and create a new Space and copy the Space ID.
3. In Account details, create a new Application User and Copy the API Key and API Secret in some safe place.
4. Go to the new Application User and add the Role "Account Admin" in the Roles section.
5. Go to your Space > Configuration > Processors and Configure Processor to add the payment methods you want to use  (Paypal, Twint, etc).
6. Go to  Webhooks > URL Tab and create webhook URL. Add the URL of your website (you can copy from the Craft when you have created the gateway).
7. Go to Webhooks > Listener Tab and create webhook Listener. In Entity select "Transaction" and in Entity State select all states. In URL select the webhook URL you have created in the previous step.
8. Go to the plugin setting in the craf backend and map the Wallee/Postfinance status to the order status.


## Craft Setup

1. Go to Commerce > System Settings > Gateways and create a new gateway.
2. Select Wallee as the gateway type.
3. Enter the Space ID, API Key and API Secret you have copied in the Wallee Setup.
4. Select the integration mode you want to use (Lightbox, iFrame or Page) [More details](https://en.wallee.com/developer/checkout).
5. When you have created the gateway you can see the Webhook URL that you have to use in the Wallee Setup.

```bash
<form method="post" accept-charset="UTF-8">
    {{ csrfInput() }}
    {{ actionInput('commerce/payments/pay') }}
    {{ hiddenInput('gatewayId', cart.gatewayId) }}
    {% set params = {
        successUrl: '/checkout/success?number=' ~ cart.number,
        cancelUrl: '/checkout/cancel?number=' ~ cart.number,
        paymentButtonSelector: '#wallee-lightbox', 
    } %}
    {{ cart.gateway.getPaymentFormHtml(params)|raw }}
</form>
```

The parameter paymentButtonSelector: '#wallee-lightbox', is optional and only neeeded if you want to open the lightbox when you click on a button. If you don't use this parameter the lightbox will open automatically when the page is loaded. It's not required for the iframe and page integration.

### How can I show the Wallee transactions id in the orders list in the craft backend?

1. Go to Commerce > Orders > Click on the cog icon > Customize Sources.
2. In the "Default Table Columns" add the column "Transactions" and save.

## Support

If you have any issues with this plugin, please [create an issue](https://github.com/tonioseiler/craft-commerce-wallee/issues) on GitHub or contact us at [Furbo](mailto:support@furbo.ch).