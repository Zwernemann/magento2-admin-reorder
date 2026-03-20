# Magento Fix Admin EditOrder

Fixes the "Edit Order" function in Magento 2 admin for stores with MSI disabled.

Related upstream issue: [magento/magento2#39958](https://github.com/magento/magento2/issues/39958) and  [magento/magento2#39898](https://github.com/magento/magento2/issues/39898)

---

## The Problem

When you click "Edit Order" on a non-MSI Magento store and the old order holds
all available stock, three things go wrong:

**1. Items grid is empty**
`addProduct()` calls `Product::isSalable()` early in its flow, which reads
`is_in_stock` from the StockRegistry. Since the old order holds all stock,
`is_in_stock=false` — the item is silently dropped before it ever reaches the quote.

**2. Items grid blank after the redirect**
`initFromOrder()` saves the new quote but never stores the quote ID in the admin
session. The next request (the edit form) finds no quote and creates a fresh
empty one instead.

**3. Submit blocked with "out of stock"**
`Grid::getItems()` runs stock checks with `IsSuperMode=false`, setting
`hasError=true` on items whose stock is tied up by the old order.
`_validate()` picks up those flags and refuses to place the new order.

---

## What This Module Does

| Plugin | What it fixes |
|--------|---------------|
| `aroundInitFromOrder` | Temporarily sets `is_in_stock=true` in the in-memory StockRegistry so `isSalable()` passes. Calls `setQuoteId()` after save so the session finds the right quote. Registry is restored in `finally` — no DB write, no effect on other requests. |
| `aroundCreateOrder` | Clears stale `hasError` flags from the grid before `_validate()` runs. |
| `afterGetItems` (Grid) | Suppresses "out of stock" errors for items whose quantity is covered by the original order. The stock gets freed when the old order is cancelled on submit. Items that are genuinely short stay flagged. |
| `aroundValidateForCart` | Skips `QuoteAddressValidator` in admin — it's a storefront guard that throws `NoSuchEntityException` on copied order addresses in admin context. Admin-only (`adminhtml/di.xml`). |
| `aroundValidateBeforeSubmit` | Skips `QuoteValidator::validateBeforeSubmit()` in admin — false positive because the old order's reservations are still active at submit time. Admin-only (`adminhtml/di.xml`). |

---

## Requirements

- Magento 2.4.x
- MSI disabled (`Magento_Inventory*` modules disabled)

---

## Installation

```bash
cp -r app/code/Zwernemann /path/to/magento/app/code/
bin/magento setup:di:compile
bin/magento cache:flush
```

---

## License

MIT — Copyright (c) 2026 Zwernemann

## Contact

**Zwernemann Medienentwicklung**\
Martin Zwernemann\
79730 Murg, Germany

[To the website](https://www.zwernemann.de/)
