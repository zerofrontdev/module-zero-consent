<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Test\Unit\Controller\Account;

use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\View\Page\Config as PageConfig;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;
use Zero\Consent\Controller\Account\ConfirmDelete;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\DeletionRequests;
use Zero\Consent\Test\Unit\SessionStub;

/**
 * The emailed deletion link's GET: the token moves from the URL into the session (302, no-referrer), the page is
 * NOINDEX; a GET never takes the token, so a mail scanner deletes nothing.
 */
class ConfirmDeleteTest extends TestCase
{
    use SessionStub;

    private const TOKEN = 'AbCdEfGhIjKlMnOpQrStUvWxYz0123456789abcdefg';

    private ?string $param = null;
    private ?int $peeks = 5;
    /** @var array<string, string> */
    private array $redirected = [];
    /** @var string[] */
    private array $errors = [];
    /** @var string[] */
    private array $page = [];

    private function controller(): ConfirmDelete
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getParam')->willReturnCallback(fn (string $name) => $name === 'token' ? $this->param : null);
        $redirect = $this->createStub(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function (string $path) use (&$redirect) {
            $this->redirected['path'] = $path;

            return $redirect;
        });
        $redirect->method('setHeader')->willReturnCallback(function (string $name, string $value) use (&$redirect) {
            $this->redirected[$name] = $value;

            return $redirect;
        });
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($redirect);
        $messages = $this->createStub(ManagerInterface::class);
        $messages->method('addErrorMessage')->willReturnCallback(function ($m) use (&$messages) {
            $this->errors[] = (string) $m;

            return $messages;
        });
        $requests = $this->createStub(DeletionRequests::class);
        $requests->method('peek')->willReturnCallback(fn (string $t) => $t === self::TOKEN ? $this->peeks : null);
        $requests->method('take')->willReturnCallback(function () {
            $this->fail('a GET never takes the token');
        });
        $pageConfig = $this->createStub(PageConfig::class);
        $pageConfig->method('setRobots')->willReturnCallback(function (string $robots) {
            $this->page[] = 'robots:' . $robots;
        });
        $pageConfig->method('getTitle')->willReturn($this->createStub(Title::class));
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($pageConfig);
        $page->method('setHeader')->willReturnCallback(function (string $name, string $value) use (&$page) {
            $this->page[] = $name . ':' . $value;

            return $page;
        });
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);
        $config = $this->createStub(Config::class);
        $config->method('privacyEnabled')->willReturn(true);

        return new ConfirmDelete(
            $request,
            $pages,
            $this->createStub(ForwardFactory::class),
            $redirects,
            $this->customerSession(),
            $messages,
            $requests,
            $config
        );
    }

    public function testIsNotAPostAction(): void
    {
        $this->assertNotInstanceOf(HttpPostActionInterface::class, $this->controller());
    }

    public function testTokenMovesFromTheUrlToTheSession(): void
    {
        $this->param = self::TOKEN;

        $this->controller()->execute();

        $this->assertSame(self::TOKEN, $this->sessionData[DeletionRequests::SESSION_KEY]);
        $this->assertSame(['path' => 'privacy/account/confirmdelete', 'Referrer-Policy' => 'no-referrer'], $this->redirected);
    }

    public function testANewlineTokenIsRefused(): void
    {
        $this->param = self::TOKEN . "\n";

        $this->controller()->execute();

        $this->assertArrayNotHasKey(DeletionRequests::SESSION_KEY, $this->sessionData);
        $this->assertSame(['This link has expired or was used already. Ask for a new one under My Account > Privacy.'], $this->errors);
        $this->assertSame('', $this->redirected['path']);
    }

    public function testThePageIsNoindexAndOnlyPeeks(): void
    {
        $this->sessionData[DeletionRequests::SESSION_KEY] = self::TOKEN;

        $this->assertInstanceOf(Page::class, $this->controller()->execute());

        $this->assertSame(['robots:NOINDEX,NOFOLLOW', 'Referrer-Policy:no-referrer'], $this->page);
        $this->assertSame(self::TOKEN, $this->sessionData[DeletionRequests::SESSION_KEY]);
    }

    public function testAUsedTokenInTheSessionShowsNoPage(): void
    {
        $this->sessionData[DeletionRequests::SESSION_KEY] = self::TOKEN;
        $this->peeks = null;

        $this->controller()->execute();

        $this->assertSame([], $this->page);
        $this->assertCount(1, $this->errors);
    }
}
