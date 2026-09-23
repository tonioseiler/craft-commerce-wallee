# Commerce wallee Changelog

## 2.3.21
- Ported the fixes from 5.0.12 to 5.0.15 to Craft 4:
  - Order totals are no longer recalculated in sessionless wallee callbacks, which dropped per-user coupon discounts
  - Transaction amounts are taken from the wallee transaction instead of the order balance
  - Fixed duplicate payment transactions when wallee delivers more than one webhook for the same state
  - The order status is only changed, and emails sent, when the status actually changes
- Fixed orders being marked as unpaid when webhooks and the success redirect record the same payment at the same time (transactions are now recorded under a per-order lock)
- Transactions with a non-positive amount are never saved
- The success redirect only records a payment when wallee confirms a fulfilled transaction
- The amount sent to wallee is now the order's outstanding balance in the payment currency, matching what Commerce records
- Replaced leftover `dd()` calls with `NotImplementedException`
- `CheckoutResponse` methods now return valid values instead of `null`
- Payment recording failures are logged as errors

## 2.3.1 - 2023-12-11
- Fix Bug in Webhooklistener

## 2.3 - 2023-10-03
- Make payment from the backend

## 2.2.8 - 2023-09-08
 - Typo fixed
 - 
## 2.2.6 - 2023-09-08
 - Bug with label of shipping costs in wallee backend fixed
 - Fix webhook error handling for non existing order ids
 - Send correct currency to wallee
 - Refund of order implemented


## 2.2.0 - 2023-07-07
Iframe: js bug

## 2.2.0 - 2023-06-14
Added discount and delivery support.

## 2.1.0 - 2023-03-14
Added postfinance support.

## 2.0.0 - 2023-02-02

### Added
- Initial release for Craft 4