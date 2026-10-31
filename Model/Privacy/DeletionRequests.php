<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Model\Privacy;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;

/**
 * zero_privacy_deletion: the emailed links that delete an account without a
 * password. One per account (a new request replaces the token, at most one
 * a minute); the token is kept as its SHA-256 only, lives an hour and is
 * used once. Times are UTC from PHP.
 */
class DeletionRequests
{
    public const TTL = 3600;
    public const THROTTLE = 60;
    /** Where the link's page keeps the token once it is out of the URL. */
    public const SESSION_KEY = 'zero_privacy_delete_token';

    private const TABLE = 'zero_privacy_deletion';
    private const TOKEN_PATTERN = '/^[A-Za-z0-9]{43}\z/';

    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly Random $random
    ) {
    }

    /**
     * @return string|null The token for the link; null when one was made less than a minute ago
     */
    public function create(int $customerId): ?string
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $connection->delete($table, ['expires_at < ?' => gmdate('Y-m-d H:i:s')]);
        $recent = $connection->fetchOne(
            $connection->select()
                ->from($table, ['customer_id'])
                ->where('customer_id = ?', $customerId)
                ->where('expires_at > ?', gmdate('Y-m-d H:i:s', time() + self::TTL - self::THROTTLE))
        );
        if ($recent) {
            return null;
        }
        $token = $this->random->getRandomString(43, Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS);
        $connection->insertOnDuplicate($table, [
            'customer_id' => $customerId,
            'token_hash' => hash('sha256', $token),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + self::TTL),
        ], ['token_hash', 'expires_at']);

        return $token;
    }

    /**
     * The account of an open request, left in place (a GET may look).
     */
    public function peek(string $token): ?int
    {
        $row = $this->find($token);

        return $row && $row['expires_at'] > gmdate('Y-m-d H:i:s') ? (int) $row['customer_id'] : null;
    }

    /**
     * The account of an open request, removed so it can't be used again; of
     * two requests at once, only one gets it.
     */
    public function take(string $token): ?int
    {
        $row = $this->find($token);
        if (!$row) {
            return null;
        }
        $deleted = $this->resource->getConnection()->delete($this->resource->getTableName(self::TABLE), [
            'customer_id = ?' => (int) $row['customer_id'],
            'token_hash = ?' => (string) $row['token_hash'],
        ]);

        return $deleted === 1 && $row['expires_at'] > gmdate('Y-m-d H:i:s') ? (int) $row['customer_id'] : null;
    }

    public static function valid(string $token): bool
    {
        return preg_match(self::TOKEN_PATTERN, $token) === 1;
    }

    private function find(string $token): ?array
    {
        if (!self::valid($token)) {
            return null;
        }
        $hash = hash('sha256', $token);
        $connection = $this->resource->getConnection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE))
                ->where('token_hash = ?', $hash)
        );

        return $row && hash_equals((string) $row['token_hash'], $hash) ? $row : null;
    }
}
