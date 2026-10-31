<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Test\Unit\Model\Privacy;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Math\Random;
use PHPUnit\Framework\TestCase;
use Zero\Consent\Model\Privacy\DeletionLink;
use Zero\Consent\Model\Privacy\DeletionMailer;
use Zero\Consent\Model\Privacy\DeletionRequests;
use Zero\Consent\Test\Unit\DbStub;

/**
 * The emailed deletion link's token: 43 letters and digits (`\z`), kept as its SHA-256 only (hash_equals), an hour,
 * one per account, at most one a minute (throttle read from expires_at), used once (only one of two takes gets it).
 */
class DeletionRequestsTest extends TestCase
{
    use DbStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private function requests(array $answers = [], ?Random $random = null): DeletionRequests
    {
        if (!$random) {
            $random = $this->createStub(Random::class);
            $random->method('getRandomString')->willReturn(self::TOKEN);
        }

        return new DeletionRequests($this->resource($this->connection($answers)), $random);
    }

    private function row(int $expiresIn, ?string $hash = null): array
    {
        return [
            'customer_id' => '5',
            'token_hash' => $hash ?? hash('sha256', self::TOKEN),
            'expires_at' => gmdate('Y-m-d H:i:s', time() + $expiresIn),
        ];
    }

    public function testTokenFormat(): void
    {
        $this->assertTrue(DeletionRequests::valid(self::TOKEN));
        $this->assertFalse(DeletionRequests::valid(self::TOKEN . "\n"));
        $this->assertFalse(DeletionRequests::valid(substr(self::TOKEN, 1)));
        $this->assertFalse(DeletionRequests::valid(substr(self::TOKEN, 1) . '_'));

        $resource = $this->createMock(ResourceConnection::class);
        $resource->expects($this->never())->method('getConnection');
        $requests = new DeletionRequests($resource, $this->createStub(Random::class));
        $this->assertNull($requests->peek(self::TOKEN . "\n"));
        $this->assertNull($requests->take(self::TOKEN . "\n"));
    }

    public function testCreateStoresOnlyTheHashForAnHour(): void
    {
        $random = $this->createMock(Random::class);
        $random->expects($this->once())->method('getRandomString')
            ->with(43, Random::CHARS_LOWERS . Random::CHARS_UPPERS . Random::CHARS_DIGITS)->willReturn(self::TOKEN);
        $before = time();

        $this->assertSame(self::TOKEN, $this->requests([], $random)->create(5));

        $this->assertSame(['delete', 'select.from', 'select.where', 'select.where', 'fetchOne', 'insertOnDuplicate'], $this->dbSequence());
        [$table, $data, $update] = $this->callsOf('insertOnDuplicate')[0];
        $this->assertSame('zero_privacy_deletion', $table);
        $this->assertSame(['customer_id', 'token_hash', 'expires_at'], array_keys($data));
        $this->assertSame([5, hash('sha256', self::TOKEN)], [$data['customer_id'], $data['token_hash']]);
        $this->assertEqualsWithDelta($before + 3600, strtotime($data['expires_at'] . ' UTC'), 2);
        $this->assertSame(['token_hash', 'expires_at'], $update, 'one per account: a new request replaces the token');
        $this->assertSame(['expires_at < ?'], array_keys($this->callsOf('delete')[0][1]), 'expired requests are purged');
    }

    public function testThrottleIsReadFromExpiresAt(): void
    {
        $before = time();

        $this->assertNull($this->requests(['fetchOne' => '5'])->create(5), 'one made less than a minute ago');

        $this->assertSame([], $this->callsOf('insertOnDuplicate'), 'no new token, so no second email');
        $wheres = $this->callsOf('select.where');
        $this->assertSame(['customer_id = ?', 5], $wheres[0]);
        $this->assertSame('expires_at > ?', $wheres[1][0]);
        $this->assertEqualsWithDelta(
            $before + DeletionRequests::TTL - DeletionRequests::THROTTLE,
            strtotime($wheres[1][1] . ' UTC'),
            2,
            'created within THROTTLE seconds = expires after now + TTL - THROTTLE'
        );
    }

    public function testPeekLeavesTheRequestAndRefusesAnExpiredOne(): void
    {
        $this->assertSame(5, $this->requests(['fetchRow' => $this->row(600)])->peek(self::TOKEN));
        $this->assertNull($this->requests(['fetchRow' => $this->row(-1)])->peek(self::TOKEN));
        $this->assertSame([], $this->callsOf('delete'));
        $this->assertContains(['token_hash = ?', hash('sha256', self::TOKEN)], $this->callsOf('select.where'));
    }

    public function testTakeIsSingleUse(): void
    {
        $deletes = [1, 0];
        $requests = $this->requests([
            'fetchRow' => $this->row(600),
            'delete' => function () use (&$deletes) {
                return array_shift($deletes);
            },
        ]);

        $this->assertSame(5, $requests->take(self::TOKEN));
        $this->assertNull($requests->take(self::TOKEN), 'a double POST deletes once');
        $this->assertSame(
            ['zero_privacy_deletion', ['customer_id = ?' => 5, 'token_hash = ?' => hash('sha256', self::TOKEN)]],
            $this->callsOf('delete')[0]
        );
    }

    public function testAnExpiredTokenIsSpentAndRefused(): void
    {
        $this->assertNull($this->requests(['fetchRow' => $this->row(-5), 'delete' => 1])->take(self::TOKEN));
        $this->assertCount(1, $this->callsOf('delete'));
    }

    public function testAHashMatchedOnlyLooselyIsRefused(): void
    {
        $row = $this->row(600, strtoupper(hash('sha256', self::TOKEN)));

        $this->assertNull($this->requests(['fetchRow' => $row, 'delete' => 1])->take(self::TOKEN));
        $this->assertSame([], $this->callsOf('delete'));
    }

    public function testTheLinkGoesToTheAccountsEmailAndAThrottledRequestSendsNoSecondEmail(): void
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('ann@example.com');
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);
        $tokens = [self::TOKEN, null];
        $requests = $this->createStub(DeletionRequests::class);
        $requests->method('create')->willReturnCallback(function (int $id) use (&$tokens) {
            return $id === 5 ? array_shift($tokens) : 'wrong';
        });
        $mailer = $this->createMock(DeletionMailer::class);
        $mailer->expects($this->once())->method('send')->with($customer, self::TOKEN, 3);
        $link = new DeletionLink($customers, $requests, $mailer);

        $this->assertSame('ann@example.com', $link->send(5, 3));
        $this->assertSame('ann@example.com', $link->send(5, 3), 'the same message within the minute');
    }
}
