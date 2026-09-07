# Commerce wallee Changelog

## 5.0.12
- Fixed order totals being recalculated in sessionless wallee callbacks, which dropped per-user coupon discounts and inflated the order total
- Transaction amounts are now taken from the wallee transaction instead of the order balance


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