<?php
/**
 * Copyright © 2026 Roman Pasichnyk
 * SPDX-License-Identifier: OSL-3.0 OR AFL-3.0
 */

declare(strict_types=1);

namespace Zero\Consent\Controller\Account;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\ForwardFactory;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Message\ManagerInterface;
use Magento\Framework\Phrase;
use Magento\Framework\View\Result\PageFactory;
use Zero\Consent\Model\Config;
use Zero\Consent\Model\Privacy\DeletionRequests;

/**
 * The emailed deletion link (`?token=`): the token moves into the session
 * and the browser comes back without it (no token in a page's URL, a Referer
 * or an analytics hit). The page asks to confirm with a POST
 * (ConfirmDeletePost); a GET never uses the token, so a mail scanner that
 * opens links deletes nothing. No sign-in needed: the link proves the mailbox.
 */
class ConfirmDelete implements HttpGetActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly PageFactory $pageFactory,
        private readonly ForwardFactory $forwardFactory,
        private readonly RedirectFactory $redirectFactory,
        private readonly CustomerSession $session,
        private readonly ManagerInterface $messageManager,
        private readonly DeletionRequests $requests,
        private readonly Config $config
    ) {
    }

    public function execute(): ResultInterface
    {
        if (!$this->config->privacyEnabled()) {
            return $this->forwardFactory->create()->forward('noroute');
        }
        $token = $this->request->getParam('token');
        if ($token !== null) {
            $token = is_string($token) ? $token : '';
            if (DeletionRequests::valid($token) && $this->requests->peek($token)) {
                $this->session->setData(DeletionRequests::SESSION_KEY, $token);
                $redirect = $this->redirectFactory->create()->setPath('privacy/account/confirmdelete');
            } else {
                $this->session->unsetData(DeletionRequests::SESSION_KEY);
                $this->messageManager->addErrorMessage(self::expired());
                $redirect = $this->redirectFactory->create()->setPath('');
            }
            $redirect->setHeader('Referrer-Policy', 'no-referrer', true);

            return $redirect;
        }
        $token = (string) $this->session->getData(DeletionRequests::SESSION_KEY);
        if (!DeletionRequests::valid($token) || !$this->requests->peek($token)) {
            $this->messageManager->addErrorMessage(self::expired());

            return $this->redirectFactory->create()->setPath('');
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->getConfig()->getTitle()->set(__('Delete my account'));
        $page->setHeader('Referrer-Policy', 'no-referrer', true);

        return $page;
    }

    public static function expired(): Phrase
    {
        return __('This link has expired or was used already. Ask for a new one under My Account > Privacy.');
    }
}
