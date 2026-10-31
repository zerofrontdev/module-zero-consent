<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Model\Privacy;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Registry;
use Psr\Log\LoggerInterface;

/**
 * Deletes a customer's account at their request: every section's data
 * deleted or anonymized, then the account, in one transaction. The log keeps
 * that it happened (account id and date), not who it was. After the commit
 * `zero_consent_account_erased` (customer_id) fires.
 */
class Eraser
{
    public function __construct(
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly Sections $sections,
        private readonly ResourceConnection $resource,
        private readonly Registry $registry,
        private readonly LoggerInterface $logger,
        private readonly ManagerInterface $eventManager
    ) {
    }

    public function erase(int $customerId): void
    {
        $customer = $this->customerRepository->getById($customerId);
        $connection = $this->resource->getConnection();
        $secure = $this->registry->registry('isSecureArea');
        $this->registry->register('isSecureArea', true, true);
        $connection->beginTransaction();
        try {
            $this->sections->erase($customer);
            $this->customerRepository->deleteById($customerId);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        } finally {
            $this->registry->unregister('isSecureArea');
            if ($secure !== null) {
                $this->registry->register('isSecureArea', $secure);
            }
        }
        $this->logger->info(sprintf('Zero_Consent: customer account %d deleted at the customer\'s request.', $customerId));
        $this->eventManager->dispatch('zero_consent_account_erased', ['customer_id' => $customerId]);
    }
}
