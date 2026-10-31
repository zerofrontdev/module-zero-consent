<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use Psr\Log\LoggerInterface;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\DeletionRequests;
use Zero\Consent\Model\Privacy\Eraser;

/**
 * "Delete my account" on the emailed link's page (form key checked by
 * Magento): the token is used once, the account it was made for is signed
 * out here if it is this browser's, its API tokens are revoked and
 * Model\Privacy\Eraser deletes it. Another account signed in here is left
 * alone.
 */
class ConfirmDeletePost implements HttpPostActionInterface
{
    public function __construct(
        private readonly RedirectFactory $redirectFactory,
        private readonly ManagerInterface $messageManager,
        private readonly CustomerSession $session,
        private readonly DeletionRequests $requests,
        private readonly CustomerTokenServiceInterface $tokenService,
        private readonly Eraser $eraser,
        private readonly Config $config,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): ResultInterface
    {
        $redirect = $this->redirectFactory->create();
        $token = (string) $this->session->getData(DeletionRequests::SESSION_KEY);
        $this->session->unsetData(DeletionRequests::SESSION_KEY);
        if (!$this->config->allowsDeletion()) {
            $this->messageManager->addErrorMessage(__('To delete your account and your personal data, write to us from the email address of your account. We will confirm when it is done.'));

            return $redirect->setPath('');
        }
        $customerId = $this->requests->take($token);
        if (!$customerId) {
            $this->messageManager->addErrorMessage(ConfirmDelete::expired());

            return $redirect->setPath('');
        }
        if ($this->session->isLoggedIn() && (int) $this->session->getCustomerId() === $customerId) {
            // Signed out first: Magento's logout observers write to the account (customer_log).
            $this->session->logout()->setLastCustomerId(null);
            $this->session->regenerateId();
        }
        try {
            $this->tokenService->revokeCustomerAccessToken($customerId);
        } catch (\Throwable $e) {
            $this->logger->info('Zero_Consent: revoking the API tokens of customer ' . $customerId . ' failed: ' . $e->getMessage());
        }
        try {
            $this->eraser->erase($customerId);
        } catch (\Throwable $e) {
            $this->logger->error('Zero_Consent: deleting customer ' . $customerId . ' failed: ' . $e->getMessage());
            $this->messageManager->addErrorMessage(__('We could not delete your account. Please write to us and we will do it for you.'));

            return $redirect->setPath('');
        }
        $this->logger->info(sprintf('Zero_Consent: customer account %d deleted via the emailed link.', $customerId));
        $this->messageManager->addSuccessMessage(__('Your account and your personal data have been deleted.'));

        return $redirect->setPath('');
    }
}
