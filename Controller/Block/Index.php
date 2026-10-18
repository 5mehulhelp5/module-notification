<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Controller\Block;

use CommerceKings\Notification\Api\RecipientBlockInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\View\Result\PageFactory;

/**
 * GET shows the do-not-contact confirmation for a signed link; only the form-key POST blocks or undoes, so mail
 * scanners that open links change nothing. Extends Action so Magento sets the store and currency context.
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public const STATE_INVALID = 'invalid';
    public const STATE_CONFIRM = 'confirm';
    public const STATE_BLOCKED = 'blocked';
    public const STATE_UNBLOCKED = 'unblocked';

    public function __construct(
        Context $context,
        private readonly Http $request,
        private readonly RecipientBlockInterface $blocks,
        private readonly PageFactory $pageFactory,
        private readonly Validator $formKeyValidator
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        // Magento also routes HEAD to GET actions: header inspection never renders or changes anything.
        if (!in_array($this->request->getMethod(), ['GET', 'POST'], true)) {
            return $this->resultRedirectFactory->create()->setPath('');
        }
        $recipient = $this->request->getParam('r');
        $signature = $this->request->getParam('s');
        $link = is_string($recipient) && is_string($signature)
            ? $this->blocks->fromLink($recipient, $signature) : null;
        $state = $link === null ? self::STATE_INVALID : self::STATE_CONFIRM;
        if ($link !== null && $this->request->getMethod() === 'POST') {
            if (!is_string($this->request->getParam('form_key')) || !$this->formKeyValidator->validate($this->request)) {
                return $this->resultRedirectFactory->create()->setPath('');
            }
            if ($this->request->getParam('do') === 'undo') {
                $this->blocks->unblock($link['channel'], $link['recipient']);
                $state = self::STATE_UNBLOCKED;
            } else {
                $this->blocks->block($link['channel'], $link['recipient']);
                $state = self::STATE_BLOCKED;
            }
        } elseif ($link !== null && $this->blocks->isBlocked($link['channel'], $link['recipient'])) {
            $state = self::STATE_BLOCKED;
        }
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)match ($state) {
            self::STATE_INVALID => __('Link not valid'),
            self::STATE_BLOCKED => __('Emails stopped'),
            self::STATE_UNBLOCKED => __('Emails allowed again'),
            default => __('Stop these emails?'),
        });
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->setHeader('Referrer-Policy', 'no-referrer', true);
        $block = $page->getLayout()->getBlock('ck.notification.recipient_block');
        if ($block instanceof \Magento\Framework\View\Element\AbstractBlock) {
            $block->setData('state', $state);
            $block->setData('link_recipient', $link !== null ? $recipient : '');
            $block->setData('link_signature', $link !== null ? $signature : '');
        }
        return $page;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // reject non-string keys before Magento's middleware compares them as strings
        $key = $request->getParam('form_key');
        return $key !== null && !is_string($key) ? false : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
