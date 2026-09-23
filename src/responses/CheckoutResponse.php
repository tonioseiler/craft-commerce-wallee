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
        return false;
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
        return [];
    }

    public function getRedirectUrl(): string
    {
        return '';
    }

    public function getTransactionReference(): string
    {
        return "";
    }

    public function getCode(): string
    {
        return "";
    }

    public function getData(): string
    {
        return "ok";
    }

    public function getMessage(): string
    {
        return "";
    }

    public function redirect(): void
    {
    }
}