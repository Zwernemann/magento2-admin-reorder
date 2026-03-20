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

use Magento\Quote\Api\Data\AddressInterface;
use Magento\Quote\Api\Data\CartInterface;
use Magento\Quote\Model\QuoteAddressValidator;

/**
 * Skips QuoteAddressValidator::validateForCart() in the admin area.
 *
 * During order edit, initFromOrder() copies the original billing/shipping
 * address (including customer_address_id) into the new quote. When the quote
 * is saved, BillingAddressPersister calls validateForCart() which checks
 * whether the address belongs to the customer on the quote. In admin context
 * the customer object is not fully loaded, so the check throws
 * NoSuchEntityException even though the address is valid.
 * Registered in adminhtml/di.xml only — storefront checkout is not affected.
 */
class QuoteAddressValidatorPlugin
{
    public function aroundValidateForCart(
        QuoteAddressValidator $subject,
        callable $proceed,
        CartInterface $cart,
        AddressInterface $address
    ): void {
        // Skip — this is a storefront guard, not needed in admin.
    }
}
