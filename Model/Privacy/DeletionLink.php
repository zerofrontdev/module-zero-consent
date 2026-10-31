<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Model\Privacy;

use Magento\Customer\Api\CustomerRepositoryInterface;

/**
 * "Email me a link to delete my account" (an account without a password),
 * for the theme's Privacy page and the headless storefront alike.
 */
class DeletionLink
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly DeletionRequests $requests,
        private readonly DeletionMailer $mailer
    ) {
    }

    /**
     * @return string The account's email, where the link goes (none again within a minute)
     */
    public function send(int $customerId, int $storeId): string
    {
        $customer = $this->customerRepository->getById($customerId);
        $token = $this->requests->create($customerId);
        if ($token !== null) {
            $this->mailer->send($customer, $token, $storeId);
        }

        return (string) $customer->getEmail();
    }
}
