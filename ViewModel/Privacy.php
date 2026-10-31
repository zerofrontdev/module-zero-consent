<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\ViewModel;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\Accounts;

/**
 * What My Account > Privacy offers in this store view.
 */
class Privacy implements ArgumentInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly CustomerSession $customerSession,
        private readonly Accounts $accounts
    ) {
    }

    /** The account deletes itself with its password; false: through an emailed link (no password). */
    public function needsPassword(): bool
    {
        return $this->accounts->hasPassword((int) $this->customerSession->getCustomerId());
    }

    public function allowsDeletion(): bool
    {
        return $this->config->allowsDeletion();
    }

    public function anonymizesOrders(): bool
    {
        return $this->config->anonymizesOrders();
    }

    /** The privacy policy's URL, '' when none is set. */
    public function policyUrl(): string
    {
        return $this->config->privacyUrl();
    }
}
