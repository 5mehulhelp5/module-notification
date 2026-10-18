<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Controller\Block;

use CommerceKings\Notification\Api\RecipientBlockInterface;
use CommerceKings\Notification\Controller\Block\Index;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Data\Form\FormKey\Validator;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\LayoutInterface;
use Magento\Framework\View\Page\Config;
use Magento\Framework\View\Page\Title;
use Magento\Framework\View\Result\Page;
use Magento\Framework\View\Result\PageFactory;
use PHPUnit\Framework\TestCase;

/** The stop page: GET and HEAD change nothing, only a form-key POST blocks or undoes. */
class IndexTest extends TestCase
{
    private array $calls = [];
    private array $blockData = [];
    private ?Redirect $redirect = null;
    private bool $validKey = true;
    private bool $alreadyBlocked = false;
    private ?int $status = null;
    private array $post = [];
    private string $contentType = 'application/x-www-form-urlencoded';

    private function controller(string $method, array $params): Index
    {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParam')->willReturnCallback(fn ($name) => $params[$name] ?? null);
        $request->method('getQuery')->willReturnCallback(fn ($name) => $params[$name] ?? null);
        $request->method('getPost')->willReturnCallback(fn ($name) => $this->post[$name] ?? null);
        $request->method('getPostValue')->willReturnCallback(fn () => $this->post);
        $request->method('getHeader')->willReturnCallback(fn () => $this->contentType);
        $blocks = $this->createStub(RecipientBlockInterface::class);
        $blocks->method('fromLink')->willReturnCallback(function ($r, $s) {
            $this->calls[] = ['fromLink', $r, $s];
            return $r === 'enc' && $s === 'sig' ? ['channel' => 'email', 'recipient' => 'a@example.test'] : null;
        });
        $blocks->method('isBlocked')->willReturnCallback(fn () => $this->alreadyBlocked);
        $blocks->method('block')->willReturnCallback(function ($c, $r) { $this->calls[] = ['block', $c, $r]; });
        $blocks->method('unblock')->willReturnCallback(function ($c, $r) { $this->calls[] = ['unblock', $c, $r]; });
        $this->redirect = $this->createStub(Redirect::class);
        $this->redirect->method('setPath')->willReturnSelf();
        $redirects = $this->createStub(RedirectFactory::class);
        $redirects->method('create')->willReturn($this->redirect);
        $context = $this->createStub(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirects);
        $template = $this->createStub(Template::class);
        $template->method('setData')->willReturnCallback(function ($key, $value) use ($template) {
            $this->blockData[$key] = $value;
            return $template;
        });
        $layout = $this->createStub(LayoutInterface::class);
        $layout->method('getBlock')->willReturn($template);
        $config = $this->createStub(Config::class);
        $config->method('getTitle')->willReturn($this->createStub(Title::class));
        $page = $this->createStub(Page::class);
        $page->method('getConfig')->willReturn($config);
        $page->method('getLayout')->willReturn($layout);
        $pages = $this->createStub(PageFactory::class);
        $pages->method('create')->willReturn($page);
        $validator = $this->createStub(Validator::class);
        $validator->method('validate')->willReturnCallback(fn () => $this->validKey);
        $raw = $this->createStub(\Magento\Framework\Controller\Result\Raw::class);
        $raw->method('setHttpResponseCode')->willReturnCallback(function ($code) use ($raw) {
            $this->status = $code;
            return $raw;
        });
        $raw->method('setHeader')->willReturnSelf();
        $raw->method('setContents')->willReturnSelf();
        $rawFactory = $this->createStub(\Magento\Framework\Controller\Result\RawFactory::class);
        $rawFactory->method('create')->willReturn($raw);
        return new Index($context, $request, $blocks, $pages, $validator, $rawFactory);
    }

    private function changes(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] !== 'fromLink'));
    }

    public function testGetShowsTheConfirmationAndChangesNothing(): void
    {
        $result = $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertNotSame($this->redirect, $result);
        $this->assertSame([], $this->changes(), 'a mail scanner opening the link blocks nothing');
        $this->assertSame(Index::STATE_CONFIRM, $this->blockData['state']);
        $this->assertSame(['enc', 'sig'], [$this->blockData['link_recipient'], $this->blockData['link_signature']]);
    }

    public function testGetOfABlockedRecipientOffersUndo(): void
    {
        $this->alreadyBlocked = true;
        $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame(Index::STATE_BLOCKED, $this->blockData['state']);
        $this->assertSame([], $this->changes());
    }

    public function testHeadRedirectsWithoutReadingTheLink(): void
    {
        $result = $this->controller('HEAD', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame($this->redirect, $result);
        $this->assertSame([], $this->calls);
    }

    public function testPostWithoutAValidFormKeyChangesNothing(): void
    {
        foreach (['no key' => [], 'an array key' => ['form_key' => ['x']], 'a wrong key' => ['form_key' => 'bad']]
            as $case => $key) {
            $this->validKey = $case !== 'a wrong key';
            $result = $this->controller('POST', ['r' => 'enc', 's' => 'sig'] + $key)->execute();
            $this->assertSame($this->redirect, $result, $case);
        }
        $this->assertSame([], $this->changes());
    }

    public function testPostWithAFormKeyBlocks(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok'])->execute();
        $this->assertSame([['block', 'email', 'a@example.test']], $this->changes());
        $this->assertSame(Index::STATE_BLOCKED, $this->blockData['state']);
    }

    public function testUndoUnblocks(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok', 'do' => 'undo'])->execute();
        $this->assertSame([['unblock', 'email', 'a@example.test']], $this->changes());
        $this->assertSame(Index::STATE_UNBLOCKED, $this->blockData['state']);
    }

    public function testForgedOrMalformedLinksChangeNothingAndCarryNoLink(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'forged', 'form_key' => 'ok'])->execute();
        $this->assertSame(Index::STATE_INVALID, $this->blockData['state']);
        $this->assertSame(['', ''], [$this->blockData['link_recipient'], $this->blockData['link_signature']]);
        $this->calls = [];
        $this->controller('POST', ['r' => ['enc'], 's' => 'sig', 'form_key' => 'ok'])->execute();
        $this->assertSame([], $this->calls, 'array values are never checked or acted on');
        $this->assertSame([], $this->changes());
    }

    public function testOneClickUnsubscribeBlocksWithoutAFormKey(): void
    {
        $this->post = ['List-Unsubscribe' => 'One-Click'];
        $this->controller('POST', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame(200, $this->status);
        $this->assertSame([['block', 'email', 'a@example.test']], $this->changes());
        $this->assertSame([], $this->blockData, 'no page is rendered');
    }

    public function testOneClickNeverUndoes(): void
    {
        $this->post = ['List-Unsubscribe' => 'One-Click'];
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'do' => 'undo'])->execute();
        $this->assertSame([['block', 'email', 'a@example.test']], $this->changes(), 'a one-click POST only blocks');
    }

    /** @dataProvider badOneClicks */
    #[\PHPUnit\Framework\Attributes\DataProvider('badOneClicks')]
    public function testMalformedOrForgedOneClicksChangeNothing(array $post, string $type, string $signature): void
    {
        $this->post = $post;
        $this->contentType = $type;
        $this->controller('POST', ['r' => 'enc', 's' => $signature])->execute();
        $this->assertSame(400, $this->status);
        $this->assertSame([], $this->changes());
    }

    public static function badOneClicks(): array
    {
        $body = ['List-Unsubscribe' => 'One-Click'];
        return [
            'forged link' => [$body, 'application/x-www-form-urlencoded', 'forged'],
            'other value' => [['List-Unsubscribe' => 'yes'], 'application/x-www-form-urlencoded', 'sig'],
            'extra field' => [$body + ['do' => 'undo'], 'application/x-www-form-urlencoded', 'sig'],
            'array value' => [['List-Unsubscribe' => ['One-Click']], 'application/x-www-form-urlencoded', 'sig'],
            'json' => [$body, 'application/json', 'sig'],
        ];
    }

    public function testCsrfLetsOnlyTheOneClickShapeThroughWithoutAFormKey(): void
    {
        $this->post = ['List-Unsubscribe' => 'One-Click'];
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn('POST');
        $request->method('getPost')->willReturnCallback(fn ($name) => $this->post[$name] ?? null);
        $this->assertTrue($this->controller('GET', [])->validateForCsrf($request), 'the signed URL authorizes it');
    }

    public function testCsrfRejectsANonStringFormKey(): void
    {
        $request = $this->createStub(Http::class);
        $request->method('getParam')->willReturn(['x']);
        $this->assertFalse($this->controller('GET', [])->validateForCsrf($request));
        $string = $this->createStub(Http::class);
        $string->method('getParam')->willReturn('key');
        $this->assertNull($this->controller('GET', [])->validateForCsrf($string), 'strings go to Magento\'s check');
    }
}
