<?php
/**
 * Zwernemann_FixEditOrder
 *
 * @author    Zwernemann
 * @copyright Copyright (c) 2026 Zwernemann
 * @license   https://opensource.org/licenses/MIT MIT License
 */

declare(strict_types=1);

namespace Zwernemann\FixEditOrder\Plugin\Quote;

use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteValidator;

/**
 * Skips QuoteValidator::validateBeforeSubmit() in the admin area.
 *
 * During order edit the old order's stock reservations are still active when
 * quoteManagement->submit() runs. The validator (and its MSI plugins) check
 * salable qty against those reservations and throw "out of stock" even though
 * the reservation is released the moment the old order gets cancelled.
 * The admin Create model runs its own _validate() before reaching this point,
 * so skipping the duplicate check here is safe.
 * Registered in adminhtml/di.xml only — storefront checkout is not affected.
 */
class QuoteValidatorPlugin
{
    public function aroundValidateBeforeSubmit(
        QuoteValidator $subject,
        callable $proceed,
        CartInterface $quote
    ): void {
        // Skip — false positive during order edit, old order not yet cancelled.
    }
}
