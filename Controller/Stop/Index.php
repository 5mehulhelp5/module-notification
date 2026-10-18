<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Controller\Stop;

use CommerceKings\Notification\Api\Channel;
use CommerceKings\Notification\Api\StopListInterface;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\RawFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\View\Result\PageFactory;

/**
 * GET shows the do-not-contact confirmation for a signed link; only the form-key POST stops or undoes, so mail
 * scanners that open links change nothing. An RFC 8058 one-click POST (body exactly List-Unsubscribe=One-Click) on the
 * signed URL stops without browser state – it can never undo. A link signed before encryption:key:change still stops
 * (its key stays in env.php) but cannot undo. Extends Action so Magento sets the store and currency
 * context.
 */
class Index extends Action implements HttpGetActionInterface, HttpPostActionInterface, CsrfAwareActionInterface
{
    public const STATE_INVALID = 'invalid';
    public const STATE_CONFIRM = 'confirm';
    public const STATE_STOPPED = 'stopped';
    public const STATE_RESUMED = 'resumed';

    public function __construct(
        Context $context,
        private readonly Http $request,
        private readonly StopListInterface $stopList,
        private readonly PageFactory $pageFactory,
        private readonly Validator $formKeyValidator,
        private readonly RawFactory $rawFactory
    ) {
        parent::__construct($context);
    }

    public function execute(): ResultInterface
    {
        if ($this->isOneClickRequest($this->request)) {
            return $this->oneClick();
        }
        // Magento also routes HEAD to GET actions: header inspection never renders or changes anything.
        if (!in_array($this->request->getMethod(), ['GET', 'POST'], true)) {
            return $this->resultRedirectFactory->create()->setPath('');
        }
        $recipient = $this->request->getParam('r');
        $signature = $this->request->getParam('s');
        $link = is_string($recipient) && is_string($signature)
            ? $this->stopList->fromLink($recipient, $signature) : null;
        $state = $link === null ? self::STATE_INVALID : self::STATE_CONFIRM;
        if ($link !== null && $this->request->getMethod() === 'POST') {
            if (!is_string($this->request->getParam('form_key')) || !$this->formKeyValidator->validate($this->request)) {
                return $this->resultRedirectFactory->create()->setPath('');
            }
            if ($this->request->getParam('do') === 'undo') {
                // only a newest-key link may turn emails back on; an older one changes nothing
                if ($link['current']) {
                    $this->stopList->resume($link['channel'], $link['recipient']);
                    $state = self::STATE_RESUMED;
                } elseif ($this->stopList->isStopped($link['channel'], $link['recipient'])) {
                    $state = self::STATE_STOPPED;
                }
            } else {
                $this->stopList->stop($link['channel'], $link['recipient']);
                $state = self::STATE_STOPPED;
            }
        } elseif ($link !== null && $this->stopList->isStopped($link['channel'], $link['recipient'])) {
            $state = self::STATE_STOPPED;
        }
        // email says "emails" and "address"; any other channel (SMS, WhatsApp, ...) says "messages" and "number"
        $email = $link === null || $link['channel'] === Channel::EMAIL;
        $page = $this->pageFactory->create();
        $page->getConfig()->getTitle()->set((string)match ($state) {
            self::STATE_INVALID => __('Link not valid'),
            self::STATE_STOPPED => $email ? __('Emails stopped') : __('Messages stopped'),
            self::STATE_RESUMED => $email ? __('Emails allowed again') : __('Messages allowed again'),
            default => $email ? __('Stop these emails?') : __('Stop these messages?'),
        });
        $page->getConfig()->setRobots('NOINDEX,NOFOLLOW');
        $page->setHeader('Referrer-Policy', 'no-referrer', true);
        $block = $page->getLayout()->getBlock('ck.notification.stop');
        if ($block instanceof \Magento\Framework\View\Element\AbstractBlock) {
            $block->setData('state', $state);
            $block->setData('is_email', $email);
            $block->setData('link_recipient', $link !== null ? $recipient : '');
            $block->setData('link_signature', $link !== null ? $signature : '');
            // undo turns emails back on: only with a link signed by the newest key
            $block->setData('can_undo', $link !== null && $link['current']);
        }
        return $page;
    }

    /** The mail provider's unsubscribe: the signed URL authorizes it; the body and content type must be exact. */
    private function oneClick(): ResultInterface
    {
        $recipient = $this->request->getQuery('r');
        $signature = $this->request->getQuery('s');
        $type = strtolower(trim(explode(';', (string)$this->request->getHeader('Content-Type'))[0]));
        $link = in_array($type, ['application/x-www-form-urlencoded', 'multipart/form-data'], true)
            && $this->request->getPostValue() === ['List-Unsubscribe' => 'One-Click']
            && is_string($recipient) && is_string($signature)
            ? $this->stopList->fromLink($recipient, $signature) : null;
        if ($link !== null) {
            $this->stopList->stop($link['channel'], $link['recipient']);
        }
        return $this->rawFactory->create()->setHttpResponseCode($link !== null ? 200 : 400)
            ->setHeader('Content-Type', 'text/plain; charset=UTF-8', true)
            ->setHeader('Cache-Control', 'no-store', true)
            ->setContents($link !== null ? 'OK' : 'Invalid unsubscribe request.');
    }

    private function isOneClickRequest(RequestInterface $request): bool
    {
        return $request instanceof Http && $request->getMethod() === 'POST'
            && $request->getPost('List-Unsubscribe') !== null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // the signed URL authorizes the mail provider's one-click POST; the browser form still needs its form key
        if ($this->isOneClickRequest($request)) {
            return true;
        }
        // reject non-string keys before Magento's middleware compares them as strings
        $key = $request->getParam('form_key');
        return $key !== null && !is_string($key) ? false : null;
    }

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }
}
