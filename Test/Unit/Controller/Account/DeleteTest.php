<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Test\Unit\Controller\Account;

use Magento\Customer\Model\AuthenticationInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Exception\InvalidEmailOrPasswordException;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\Attributes\AllowMockObjectsWithoutExpectations;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Consent\Controller\Account\Delete;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\Accounts;
use Zero\Consent\Model\Privacy\DeletionLink;
use Zero\Consent\Model\Privacy\Eraser;
use Zero\Consent\Test\Unit\SessionStub;

/**
 * "Delete my account": an account with a password works as before (password, sign-out, erase); one without gets an
 * emailed link and nothing else happens; the confirmation box comes first for both (A5-12).
 *
 * The collaborators are mocks so each test can add expectations to the ones it is about.
 */
#[AllowMockObjectsWithoutExpectations]
class DeleteTest extends TestCase
{
    use SessionStub;

    private AuthenticationInterface&MockObject $authentication;
    private Eraser&MockObject $eraser;
    private Accounts&MockObject $accounts;
    private DeletionLink&MockObject $link;
    private ManagerInterface&MockObject $messages;
    /** @var array<string, mixed> */
    private array $params = ['confirm' => '1', 'password' => 'Secret123'];
    private ?string $path = null;

    protected function setUp(): void
    {
        $this->authentication = $this->createMock(AuthenticationInterface::class);
        $this->eraser = $this->createMock(Eraser::class);
        $this->accounts = $this->createMock(Accounts::class);
        $this->link = $this->createMock(DeletionLink::class);
        $this->messages = $this->createMock(ManagerInterface::class);
    }

    private function controller(): Delete
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $this->params[$name] ?? null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use (&$redirect) {
            $this->path = $path;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $config = $this->createStub(Config::class);
        $config->method('allowsDeletion')->willReturn(true);
        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(3);
        $stores = $this->createStub(StoreManagerInterface::class);
        $stores->method('getStore')->willReturn($store);

        return new Delete(
            $request,
            $redirects,
            $this->messages,
            $this->customerSession(true, 5),
            $this->authentication,
            $this->eraser,
            $config,
            $this->createStub(LoggerInterface::class),
            $this->accounts,
            $this->link,
            $stores
        );
    }

    private function message(string $expected): \PHPUnit\Framework\Constraint\Callback
    {
        return $this->callback(fn ($message) => (string) $message === $expected);
    }

    public function testPasswordAccountIsUnchanged(): void
    {
        $this->accounts->method('hasPassword')->with(5)->willReturn(true);
        $this->authentication->expects($this->once())->method('authenticate')->with(5, 'Secret123');
        $this->eraser->expects($this->once())->method('erase')->with(5)->willReturnCallback(function () {
            $this->assertSame(['logout', 'setLastCustomerId', 'regenerateId'], $this->sessionCalls, 'signed out first');
        });
        $this->link->expects($this->never())->method('send');
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->message('Your account and your personal data have been deleted.'));

        $this->controller()->execute();

        $this->assertSame('', $this->path);
    }

    public function testPasswordlessAccountGetsALinkAndNothingIsDeleted(): void
    {
        $this->params = ['confirm' => '1'];
        $this->accounts->method('hasPassword')->with(5)->willReturn(false);
        $this->link->expects($this->once())->method('send')->with(5, 3)->willReturn('ann@example.com');
        $this->authentication->expects($this->never())->method('authenticate');
        $this->eraser->expects($this->never())->method('erase');
        $this->messages->expects($this->once())->method('addSuccessMessage')
            ->with($this->message('We\'ve emailed a link to ann@example.com. Open it within an hour to delete your account.'));

        $this->controller()->execute();

        $this->assertSame([], $this->sessionCalls, 'nobody is signed out');
        $this->assertSame('privacy/account/index', $this->path);
    }

    public function testConfirmBoxIsStillRequired(): void
    {
        $this->params = [];
        $this->accounts->expects($this->never())->method('hasPassword');
        $this->link->expects($this->never())->method('send');
        $this->eraser->expects($this->never())->method('erase');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->message('Tick the box to confirm that you want to delete your account.'));

        $this->controller()->execute();

        $this->assertSame('privacy/account/index', $this->path);
    }

    public function testAWrongPasswordDeletesNothing(): void
    {
        $this->accounts->method('hasPassword')->willReturn(true);
        $this->authentication->method('authenticate')->willThrowException(new InvalidEmailOrPasswordException(__('x')));
        $this->eraser->expects($this->never())->method('erase');
        $this->messages->expects($this->once())->method('addErrorMessage')
            ->with($this->message('That password is not right. Your account was not deleted.'));

        $this->controller()->execute();

        $this->assertSame([], $this->sessionCalls);
        $this->assertSame('privacy/account/index', $this->path);
    }
}
