<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Model\Privacy;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\Area;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Mail\Template\TransportBuilder;
use Magento\Framework\Translate\Inline\StateInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * The email with the link that deletes an account without a password, to
 * the account's own address (from the sender of Magento's password emails).
 */
class DeletionMailer
{
    public const XML_PATH_TEMPLATE = 'zero_consent/privacy/delete_email_template';
    private const XML_PATH_IDENTITY = 'customer/password/forgot_email_identity';

    public function __construct(
        private readonly TransportBuilder $transportBuilder,
        private readonly StateInterface $inlineTranslation,
        private readonly StoreManagerInterface $storeManager,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LoggerInterface $logger
    ) {
    }

    public function send(CustomerInterface $customer, string $token, int $storeId): void
    {
        $store = $this->storeManager->getStore($storeId);
        $name = trim($customer->getFirstname() . ' ' . $customer->getLastname());
        $this->inlineTranslation->suspend();
        try {
            $this->transportBuilder
                ->setTemplateIdentifier(
                    (string) $this->scopeConfig->getValue(self::XML_PATH_TEMPLATE, ScopeInterface::SCOPE_STORE, $storeId)
                        ?: 'zero_consent_privacy_delete_email_template'
                )
                ->setTemplateOptions(['area' => Area::AREA_FRONTEND, 'store' => $storeId])
                ->setTemplateVars([
                    'name' => $name,
                    'email' => (string) $customer->getEmail(),
                    'confirm_url' => $store->getUrl('privacy/account/confirmdelete', [
                        '_query' => ['token' => $token],
                        '_secure' => true,
                        '_nosid' => true,
                    ]),
                    'store_name' => (string) $store->getFrontendName(),
                ])
                ->setFromByScope(
                    (string) $this->scopeConfig->getValue(self::XML_PATH_IDENTITY, ScopeInterface::SCOPE_STORE, $storeId)
                        ?: 'support',
                    $storeId
                )
                ->addTo((string) $customer->getEmail(), $name)
                ->getTransport()
                ->sendMessage();
        } catch (\Throwable $e) {
            $this->logger->error(
                'Zero_Consent: the account deletion link email to customer ' . $customer->getId() . ' failed: ' . $e->getMessage()
            );
        } finally {
            $this->inlineTranslation->resume();
        }
    }
}
