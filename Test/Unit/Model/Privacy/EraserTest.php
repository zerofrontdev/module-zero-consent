<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Test\Unit\Model\Privacy;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Consent\Model\Privacy\Eraser;
use Zero\Consent\Model\Privacy\Sections;
use Zero\Consent\Test\Unit\DbStub;

/**
 * zero_consent_account_erased (customer_id) fires after the commit, never for a deletion that was rolled back.
 */
class EraserTest extends TestCase
{
    use DbStub;

    private bool $deleteFails = false;

    private function eraser(): Eraser
    {
        $customer = $this->createStub(CustomerInterface::class);
        $customers = $this->createStub(CustomerRepositoryInterface::class);
        $customers->method('getById')->willReturn($customer);
        $customers->method('deleteById')->willReturnCallback(function (int $id) {
            $this->dbCalls[] = ['deleteById', [$id]];
            if ($this->deleteFails) {
                throw new \RuntimeException('FK');
            }

            return true;
        });
        $events = $this->createStub(ManagerInterface::class);
        $events->method('dispatch')->willReturnCallback(function (string $name, array $data) {
            $this->dbCalls[] = ['dispatch', [$name, $data]];
        });

        return new Eraser(
            $customers,
            $this->createStub(Sections::class),
            $this->resource($this->connection()),
            new Registry(),
            $this->createStub(LoggerInterface::class),
            $events
        );
    }

    public function testTheEventFiresAfterTheCommit(): void
    {
        $this->eraser()->erase(5);

        $this->assertSame(['beginTransaction', 'deleteById', 'commit', 'dispatch'], $this->dbSequence());
        $this->assertSame([['zero_consent_account_erased', ['customer_id' => 5]]], $this->callsOf('dispatch'));
    }

    public function testARolledBackDeletionFiresNothing(): void
    {
        $this->deleteFails = true;

        try {
            $this->eraser()->erase(5);
            $this->fail('RuntimeException expected');
        } catch (\RuntimeException $e) {
            $this->assertSame('FK', $e->getMessage());
        }
        $this->assertSame(['beginTransaction', 'deleteById', 'rollBack'], $this->dbSequence());
    }
}
