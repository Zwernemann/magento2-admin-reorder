<?php
/**
 * Zwernemann_FixEditOrder
 *
 * @author    Zwernemann
 * @copyright Copyright (c) 2026 Zwernemann
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Zwernemann\FixEditOrder\Plugin\Block\Order\Create\Items;

use Magento\Backend\Model\Session\Quote as AdminQuoteSession;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Block\Adminhtml\Order\Create\Items\Grid;

/**
 * Clears false "out of stock" errors in the order edit items grid.
 *
 * Grid::getItems() runs stock checks while the old order is still active, so
 * its stock reservation makes products appear out of stock. This plugin
 * suppresses those errors for items whose qty is covered by the original order —
 * the stock gets freed when the old order is cancelled on submit.
 * Items that are genuinely short (e.g. admin increased qty) stay flagged.
 */
class GridPlugin
{
    public function __construct(
        private readonly AdminQuoteSession $session,
        private readonly OrderRepositoryInterface $orderRepository
    ) {}

    public function afterGetItems(Grid $subject, array $result): array
    {
        $orderId = (int)$this->session->getData('order_id');

        if (!$orderId || $this->session->getReordered()) {
            return $result;
        }

        try {
            $originalOrder = $this->orderRepository->get($orderId);
        } catch (\Exception $e) {
            return $result;
        }

        $originalQtyByProduct = [];
        foreach ($originalOrder->getAllVisibleItems() as $orderItem) {
            $pid = (int)$orderItem->getProductId();
            $originalQtyByProduct[$pid] = ($originalQtyByProduct[$pid] ?? 0.0)
                + (float)$orderItem->getQtyOrdered();
        }

        foreach ($result as $quoteItem) {
            if (!$quoteItem->getHasError()) {
                continue;
            }
            $pid = (int)$quoteItem->getProduct()->getId();
            if (isset($originalQtyByProduct[$pid])
                && $originalQtyByProduct[$pid] >= (float)$quoteItem->getQty()
            ) {
                $quoteItem->setHasError(false);
                $quoteItem->setMessage('');
            }
        }

        return $result;
    }
}
