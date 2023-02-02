<?php

namespace craft\commerce\wallee\responses;

use craft\commerce\base\RequestResponseInterface;

/**
 * PayPal Checkout CheckoutResponse
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @package craft\commerce\paypalcheckout\responses
 * @since 1.0
 */
class CheckoutResponse implements RequestResponseInterface
{
    public function __construct()
    {

    }

    public function isSuccessful(): bool
    {
        return true;
    }

    public function isProcessing(): bool
    {
        // TODO: Implement isProcessing() method.
    }

    public function isRedirect(): bool
    {
        return true;
    }

    public function getRedirectMethod(): string
    {
        return "";
    }

    public function getRedirectData(): array
    {
        // TODO: Implement getRedirectData() method.
    }

    public function getRedirectUrl(): string
    {
        // TODO: Implement getRedirectUrl() method.
    }

    public function getTransactionReference(): string
    {
        return "";
    }

    public function getCode(): string
    {
        return "";
    }

    public function getData()
    {
        // TODO: Implement getData() method.
    }

    public function getMessage(): string
    {
        return "";
    }

    public function redirect()
    {
        // TODO: Implement redirect() method.
    }
}