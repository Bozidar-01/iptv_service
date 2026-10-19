# Payments

**Admin → Payments** (`admin.payments.*`; admins and resellers): prices, who has paid for their months, and what each reseller owes for credits. **Files:** `PaymentController`, `SaleService`, models `Sale` and `ResellerPayment`, `App\Support\Money`.

## Prices

- **Admins** (setting `money` in `app_settings`): the currency (default EUR), their price per month for their own users, and a default price per credit for resellers. Each reseller can get their own price per credit (`users.credit_price`), set in the same form.
- **Resellers:** their own price per month for their users (`users.price_per_month`).
- Prices are used for new sales and credits given from then on; earlier ones keep their amount.

## Sales (`sales`)

- Every time months are added to a user, a sale is recorded (`SaleService::record`): a new user with an end date, *Renew*, a later end date on the edit form (a renewal; a new subscription when there was none), bulk *Extend* and CSV import. The months are counted like credits (`App\Support\Credits`: from the current end date, or today, rounded up). Shorter or unchanged dates, trials and users without an end date are not sales.
- The seller is the user's reseller, or the admins (`owner_id` null). The amount is the months × the seller's price per month; without a price the sale has no amount (*No price*).
- The list shows not paid / paid / all, search by username, and for admins the seller. The seller (or an admin) presses **Mark paid** (`POST admin/payments/sales/{sale}`, `paid` 1/0; 403 for another reseller's sale). Totals: not paid yet, sold this month, received this month.

## Resellers and credits

- Credits an admin gives are charged at the reseller's price per credit at that moment (kept on the credit history row, `credit_transactions.price`); credits taken back are refunded at their row's price.
- Admins record what a reseller paid (**Record a payment**: amount and note, `POST admin/payments/resellers/{user}`, admins only, `reseller_payments`). The table shows charged, paid, what they owe and when they last paid. The reseller sees what they owe and their payments.
- The **Sales** page shows what each reseller owes; the **Statement** has a *Money* card: sold to and received from users that month, still unpaid, credits charged and paid for that month, and owed now (`SaleService::month`).
