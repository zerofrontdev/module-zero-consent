<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Model\Privacy;

use Magento\Customer\Model\CustomerRegistry;
use Magento\Framework\Exception\NoSuchEntityException;

/**
 * Whether an account has a password: one with a password deletes itself
 * with it; one without (made by a social sign-in) through an emailed link.
 */
class Accounts
{
    public function __construct(
        private readonly CustomerRegistry $customerRegistry
    ) {
    }

    public function hasPassword(int $customerId): bool
    {
        try {
            return (string) $this->customerRegistry->retrieveSecureData($customerId)->getPasswordHash() !== '';
        } catch (NoSuchEntityException) {
            return false;
        }
    }
}
