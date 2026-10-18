<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Test\Unit\Controller\Stop;

use CommerceKings\Notification\Api\StopListInterface;
use CommerceKings\Notification\Controller\Stop\Index;
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

/** The stop page: GET and HEAD change nothing, only a form-key POST stops or undoes. */
class IndexTest extends TestCase
{
    private array $calls = [];
    private array $blockData = [];
    private ?Redirect $redirect = null;
    private bool $validKey = true;
    private bool $alreadyStopped = false;
    /** the stored stop's reason (getReason()), null when not stopped */
    private ?string $reason = null;
    private ?int $status = null;
    private array $post = [];
    private string $contentType = 'application/x-www-form-urlencoded';
    private string $linkChannel = 'email';
    private string $title = '';

    private function controller(string $method, array $params): Index
    {
        $request = $this->createStub(Http::class);
        $request->method('getMethod')->willReturn($method);
        $request->method('getParam')->willReturnCallback(fn ($name) => $params[$name] ?? null);
        $request->method('getQuery')->willReturnCallback(fn ($name) => $params[$name] ?? null);
        $request->method('getPost')->willReturnCallback(fn ($name) => $this->post[$name] ?? null);
        $request->method('getPostValue')->willReturnCallback(fn () => $this->post);
        $request->method('getHeader')->willReturnCallback(fn () => $this->contentType);
        $stopList = $this->createStub(StopListInterface::class);
        $stopList->method('fromLink')->willReturnCallback(function ($r, $s) {
            $this->calls[] = ['fromLink', $r, $s];
            return $r === 'enc' && in_array($s, ['sig', 'oldsig'], true)
                ? ['channel' => $this->linkChannel, 'recipient' => 'a@example.test', 'current' => $s === 'sig'] : null;
        });
        $stopList->method('isStopped')->willReturnCallback(fn () => $this->alreadyStopped);
        $stopList->method('getReason')->willReturnCallback(fn () => $this->reason);
        $stopList->method('stop')->willReturnCallback(function ($c, $r) { $this->calls[] = ['stop', $c, $r]; });
        $stopList->method('resume')->willReturnCallback(function ($c, $r) { $this->calls[] = ['resume', $c, $r]; });
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
        $title = $this->createStub(Title::class);
        $title->method('set')->willReturnCallback(function ($text) { $this->title = (string)$text; });
        $config->method('getTitle')->willReturn($title);
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
        return new Index($context, $request, $stopList, $pages, $validator, $rawFactory);
    }

    private function changes(): array
    {
        return array_values(array_filter($this->calls, fn ($call) => $call[0] !== 'fromLink'));
    }

    public function testGetShowsTheConfirmationAndChangesNothing(): void
    {
        $result = $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertNotSame($this->redirect, $result);
        $this->assertSame([], $this->changes(), 'a mail scanner opening the link stops nothing');
        $this->assertSame(Index::STATE_CONFIRM, $this->blockData['state']);
        $this->assertSame(['enc', 'sig'], [$this->blockData['link_recipient'], $this->blockData['link_signature']]);
    }

    public function testGetOfAStoppedRecipientOffersUndo(): void
    {
        $this->alreadyStopped = true;
        $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame(Index::STATE_STOPPED, $this->blockData['state']);
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
        $this->assertSame([['stop', 'email', 'a@example.test']], $this->changes());
        $this->assertSame(Index::STATE_STOPPED, $this->blockData['state']);
    }

    /** Email says "emails"; any other channel (SMS, WhatsApp, ...) says "messages" and "number". */
    public function testTheWordingFollowsTheLinksChannel(): void
    {
        $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame(['Stop these emails?', true], [$this->title, $this->blockData['is_email']]);
        $this->linkChannel = 'sms';
        $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
        $this->assertSame(['Stop these messages?', false], [$this->title, $this->blockData['is_email']]);
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok'])->execute();
        $this->assertSame('Messages stopped', $this->title);
    }

    public function testUndoResumes(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok', 'do' => 'undo'])->execute();
        $this->assertSame([['resume', 'email', 'a@example.test']], $this->changes());
        $this->assertSame(Index::STATE_RESUMED, $this->blockData['state']);
    }

    /** A link from an email sent before encryption:key:change: it still stops the emails, but cannot turn them on. */
    public function testALinkSignedWithAnOlderKeyBlocksButCannotUndo(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'oldsig', 'form_key' => 'ok'])->execute();
        $this->assertSame([['stop', 'email', 'a@example.test']], $this->changes());
        $this->assertFalse($this->blockData['can_undo'], 'no Undo button for an old-key link');
        $this->calls = [];
        $this->alreadyStopped = true;
        $this->controller('POST', ['r' => 'enc', 's' => 'oldsig', 'form_key' => 'ok', 'do' => 'undo'])->execute();
        $this->assertSame([], $this->changes(), 'a forged undo with a leaked old key turns nothing back on');
        $this->assertSame(Index::STATE_STOPPED, $this->blockData['state']);
        $this->post = ['List-Unsubscribe' => 'One-Click'];
        $this->controller('POST', ['r' => 'enc', 's' => 'oldsig'])->execute();
        $this->assertSame(200, $this->status, 'one-click from the old email still works');
    }

    /** A STOP reply (or an undeliverable number) is not the link's to lift: no Undo, and a forged one changes nothing. */
    public function testTheLinksUndoNeverLiftsAStopReply(): void
    {
        foreach ([\CommerceKings\Notification\Api\StopListInterface::REASON_REPLY,
            \CommerceKings\Notification\Api\StopListInterface::REASON_UNDELIVERABLE] as $reason) {
            $this->alreadyStopped = true;
            $this->reason = $reason;
            $this->calls = [];
            $this->controller('GET', ['r' => 'enc', 's' => 'sig'])->execute();
            $this->assertSame([Index::STATE_STOPPED, false], [$this->blockData['state'], $this->blockData['can_undo']], $reason);
            $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok', 'do' => 'undo'])->execute();
            $this->assertSame([], $this->changes(), $reason . ': nothing lifted');
            $this->assertSame(Index::STATE_STOPPED, $this->blockData['state']);
        }
        $this->reason = \CommerceKings\Notification\Api\StopListInterface::REASON_LINK;
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok', 'do' => 'undo'])->execute();
        $this->assertSame([['resume', 'email', 'a@example.test']], $this->changes(), 'a link stop: Undo lifts it');
    }

    public function testANewestKeyLinkOffersUndo(): void
    {
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'form_key' => 'ok'])->execute();
        $this->assertTrue($this->blockData['can_undo']);
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
        $this->assertSame([['stop', 'email', 'a@example.test']], $this->changes());
        $this->assertSame([], $this->blockData, 'no page is rendered');
    }

    public function testOneClickNeverUndoes(): void
    {
        $this->post = ['List-Unsubscribe' => 'One-Click'];
        $this->controller('POST', ['r' => 'enc', 's' => 'sig', 'do' => 'undo'])->execute();
        $this->assertSame([['stop', 'email', 'a@example.test']], $this->changes(), 'a one-click POST only stops');
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
