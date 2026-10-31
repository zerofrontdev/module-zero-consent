<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Test\Unit\Controller\Account;

use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Integration\Api\CustomerTokenServiceInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Zero\Consent\Controller\Account\ConfirmDeletePost;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\DeletionRequests;
use Zero\Consent\Model\Privacy\Eraser;
use Zero\Consent\Test\Unit\SessionStub;

/**
 * "Delete my account" on the emailed link's page: allowed → take the token (once) → sign out only if this browser is
 * that customer → revoke the API tokens (a failure is logged) → erase. A used or forged token deletes nothing.
 */
class ConfirmDeletePostTest extends TestCase
{
    use SessionStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    /** @var string[] */
    private array $steps = [];
    /** @var string[] */
    private array $messages = [];
    /** @var string[] */
    private array $logs = [];
    private ?string $path = null;
    private ?int $takes = 5;
    private bool $allowed = true;
    private bool $revokeFails = false;
    private bool $eraseFails = false;

    protected function setUp(): void
    {
        $this->sessionData[DeletionRequests::SESSION_KEY] = self::TOKEN;
    }

    /**
     * The session calls made since the last step, then this step.
     */
    private function step(string $step): void
    {
        $this->steps = array_merge($this->steps, $this->sessionCalls, [$step]);
        $this->sessionCalls = [];
    }

    private function controller(bool $loggedIn = false, ?int $customerId = null): ConfirmDeletePost
    {
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use (&$redirect) {
            $this->path = $path;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use (&$messages) {
            $this->messages[] = 'error: ' . $m;

            return $messages;
        });
        $messages->method('addSuccessMessage')->willReturnCallback(function ($m) use (&$messages) {
            $this->messages[] = 'success: ' . $m;

            return $messages;
        });
        $session = $this->customerSession($loggedIn, $customerId);
        $requests = $this->createStub(DeletionRequests::class);
        $requests->method('take')->willReturnCallback(function (string $token) {
            $this->step('take:' . ($token === self::TOKEN ? 'token' : $token));

            return $this->takes;
        });
        $tokens = $this->createStub(CustomerTokenServiceInterface::class);
        $tokens->method('revokeCustomerAccessToken')->willReturnCallback(function (int $id) {
            $this->step('revoke:' . $id);
            if ($this->revokeFails) {
                throw new \RuntimeException('no tokens table');
            }

            return true;
        });
        $eraser = $this->createStub(Eraser::class);
        $eraser->method('erase')->willReturnCallback(function (int $id) {
            $this->step('erase:' . $id);
            if ($this->eraseFails) {
                throw new \RuntimeException('FK');
            }
        });
        $config = $this->createStub(Config::class);
        $config->method('allowsDeletion')->willReturnCallback(fn () => $this->allowed);
        $logger = $this->createStub(LoggerInterface::class);
        foreach (['info', 'error'] as $level) {
            $logger->method($level)->willReturnCallback(function (string $message) use ($level) {
                $this->logs[] = $level . ': ' . $message;
            });
        }

        return new ConfirmDeletePost($redirects, $messages, $session, $requests, $tokens, $eraser, $config, $logger);
    }

    public function testIsAPostActionWithTheFormKeyChecked(): void
    {
        $controller = $this->controller();

        $this->assertInstanceOf(HttpPostActionInterface::class, $controller);
        $this->assertNotInstanceOf(HttpGetActionInterface::class, $controller);
        $this->assertNotInstanceOf(CsrfAwareActionInterface::class, $controller);
    }

    public function testValidTokenErasesThatAccountOnce(): void
    {
        $this->controller()->execute();

        $this->assertSame(['unsetData', 'take:token', 'revoke:5', 'erase:5'], $this->steps, 'the token leaves the session first');
        $this->assertArrayNotHasKey(DeletionRequests::SESSION_KEY, $this->sessionData);
        $this->assertSame(['success: Your account and your personal data have been deleted.'], $this->messages);
        $this->assertContains('info: Zero_Consent: customer account 5 deleted via the emailed link.', $this->logs);
        $this->assertSame('', $this->path);
    }

    public function testSignedInAsThatCustomerSignsOutFirst(): void
    {
        $this->controller(true, 5)->execute();

        $this->assertSame(
            ['unsetData', 'take:token', 'logout', 'setLastCustomerId', 'regenerateId', 'revoke:5', 'erase:5'],
            $this->steps
        );
    }

    public function testSignedInAsAnotherCustomerStaysSignedIn(): void
    {
        $this->controller(true, 9)->execute();

        $this->assertNotContains('logout', $this->steps);
        $this->assertContains('erase:5', $this->steps, 'only the token\'s account is deleted');
    }

    public function testUsedTokenDeletesNothing(): void
    {
        $this->takes = null;

        $this->controller(true, 5)->execute();

        $this->assertSame(['unsetData', 'take:token'], $this->steps, 'nobody signed out, nothing revoked or erased');
        $this->assertSame(
            ['error: This link has expired or was used already. Ask for a new one under My Account > Privacy.'],
            $this->messages
        );
        $this->assertSame('', $this->path);
    }

    public function testDeletionTurnedOffMeanwhileRefusesWithoutUsingTheToken(): void
    {
        $this->allowed = false;

        $this->controller()->execute();

        $this->assertNotContains('take:token', $this->steps);
        $this->assertStringStartsWith('error: To delete your account and your personal data, write to us', $this->messages[0]);
    }

    public function testARevokeFailureIsLoggedAndTheAccountIsStillErased(): void
    {
        $this->revokeFails = true;

        $this->controller()->execute();

        $this->assertSame(['unsetData', 'take:token', 'revoke:5', 'erase:5'], $this->steps);
        $this->assertContains('info: Zero_Consent: revoking the API tokens of customer 5 failed: no tokens table', $this->logs);
        $this->assertSame(['success: Your account and your personal data have been deleted.'], $this->messages);
    }

    public function testAnEraseFailureSaysSo(): void
    {
        $this->eraseFails = true;

        $this->controller()->execute();

        $this->assertSame(['error: We could not delete your account. Please write to us and we will do it for you.'], $this->messages);
        $this->assertContains('error: Zero_Consent: deleting customer 5 failed: FK', $this->logs);
    }
}
