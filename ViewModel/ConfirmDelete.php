<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\ViewModel;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Zero\Consent\Model\Privacy\DeletionRequests;

/**
 * The emailed deletion link's page: which account it deletes (shown only to
 * whoever holds the link).
 */
class ConfirmDelete implements ArgumentInterface
{
    public function __construct(
        private readonly CustomerSession $session,
        private readonly DeletionRequests $requests,
        private readonly CustomerRepositoryInterface $customerRepository
    ) {
    }

    /** The account's email, '' when the link is no longer valid. */
    public function email(): string
    {
        $customerId = $this->requests->peek((string) $this->session->getData(DeletionRequests::SESSION_KEY));
        if (!$customerId) {
            return '';
        }
        try {
            return (string) $this->customerRepository->getById($customerId)->getEmail();
        } catch (NoSuchEntityException) {
            return '';
        }
    }
}
