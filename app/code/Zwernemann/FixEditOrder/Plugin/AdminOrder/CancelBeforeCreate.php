<?php
/**
 * Zwernemann_FixEditOrder
 *
 * @author    Zwernemann
 * @copyright Copyright (c) 2026 Zwernemann
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Zwernemann\FixEditOrder\Plugin\AdminOrder;

use Magento\CatalogInventory\Api\StockRegistryInterface;
use Magento\Sales\Model\AdminOrder\Create;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

/**
 * Fixes two bugs that break admin order edit on non-MSI installations.
 *
 * P1 — Items missing after "Edit Order"
 *   addProduct() calls Product::isSalable() which reads is_in_stock from the
 *   StockRegistry. If the old order holds all stock, is_in_stock=false and the
 *   item gets silently dropped. Fix: temporarily set is_in_stock=true in the
 *   in-memory registry (no DB write, restored in finally).
 *   Also: initFromOrder() saves the quote but never updates the session quote_id,
 *   so the next request creates a new empty quote. Fix: call setQuoteId() after save.
 *
 * P2 — Submit blocked by stale "out of stock" flags
 *   Grid::getItems() runs stock checks with IsSuperMode=false, setting hasError=true
 *   on items whose stock is held by the old order. _validate() then refuses to submit.
 *   Fix: clear those flags before _validate() runs.
 */
class CancelBeforeCreate
{
    public function __construct(
        private readonly StockRegistryInterface $stockRegistry,
        private readonly LoggerInterface $logger
    ) {}

    public function aroundInitFromOrder(Create $subject, callable $proceed, Order $order): Create
    {
        // Only relevant for genuine order edits, not reorders.
        if ($order->getReordered()) {
            return $proceed($order);
        }

        $quote        = $subject->getQuote();
        $websiteId    = (int)$quote->getStore()->getWebsiteId();
        $regOriginals = $this->applyRegistryCompensation($order, $websiteId);

        try {
            $result = $proceed($order);

            // Magento does not update the session quote_id after saving the quote
            // inside initFromOrder(). Without this the next request loads a fresh
            // empty quote and the items grid appears blank.
            if ($quoteId = $quote->getId()) {
                $subject->getSession()->setQuoteId($quoteId);
            }

            return $result;

        } catch (\Throwable $e) {
            $this->logger->error(sprintf(
                '[FixEditOrder] initFromOrder failed for order %d: %s',
                (int)$order->getId(),
                $e->getMessage()
            ));
            throw $e;

        } finally {
            $this->restoreRegistry($regOriginals);
        }
    }

    public function aroundCreateOrder(Create $subject, callable $proceed): Order
    {
        $session     = $subject->getSession();
        $isOrderEdit = !$session->getReordered() && $session->getOrder()->getId();

        if (!$isOrderEdit) {
            return $proceed();
        }

        // Grid::getItems() runs with IsSuperMode=false and sets hasError=true on
        // items whose stock is held by the old order. Clear those stale flags so
        // _validate() does not abort.
        foreach ($subject->getQuote()->getAllItems() as $item) {
            if ($item->getHasError()) {
                $item->setHasError(false);
                $item->setMessage('');
            }
        }

        try {
            return $proceed();
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('[FixEditOrder] createOrder failed: %s', $e->getMessage()));
            throw $e;
        }
    }

    /**
     * Temporarily mark each product from the old order as in-stock in the
     * in-memory StockRegistry so Product::isSalable() returns true inside addProduct().
     * Only the request-scoped registry object is touched — no DB write.
     *
     * @return array<int, array{qty: float, is_in_stock: bool}>
     */
    private function applyRegistryCompensation(Order $order, int $websiteId): array
    {
        $originals    = [];
        $qtyByProduct = [];

        foreach ($order->getAllVisibleItems() as $orderItem) {
            $pid = (int)$orderItem->getProductId();
            $qtyByProduct[$pid] = ($qtyByProduct[$pid] ?? 0.0) + (float)$orderItem->getQtyOrdered();
        }

        foreach ($qtyByProduct as $productId => $qty) {
            $stockItem = $this->stockRegistry->getStockItem($productId, $websiteId);

            if (!$stockItem->getItemId() || !$stockItem->getManageStock()) {
                continue;
            }

            $originals[$productId] = [
                'qty'         => (float)$stockItem->getQty(),
                'is_in_stock' => (bool)$stockItem->getIsInStock(),
            ];

            $stockItem->setQty($originals[$productId]['qty'] + $qty);
            $stockItem->setIsInStock(true);
        }

        return $originals;
    }

    /**
     * @param array<int, array{qty: float, is_in_stock: bool}> $originals
     */
    private function restoreRegistry(array $originals): void
    {
        foreach ($originals as $productId => $original) {
            $stockItem = $this->stockRegistry->getStockItem($productId);
            $stockItem->setQty($original['qty']);
            $stockItem->setIsInStock($original['is_in_stock']);
        }
    }
}
