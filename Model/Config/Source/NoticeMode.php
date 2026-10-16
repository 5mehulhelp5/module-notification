<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;

/** Admin dropdown "Show notice" of a feature's opt-out notice (see OptOutNoticeInterface). */
class NoticeMode implements OptionSourceInterface
{
    public const AUTOMATIC = 'auto';
    public const YES = '1';
    public const NO = '0';

    public function toOptionArray(): array
    {
        return [
            ['value' => self::AUTOMATIC, 'label' => __('Automatic – Yes without consent, No with consent')],
            ['value' => self::YES, 'label' => __('Yes')],
            ['value' => self::NO, 'label' => __('No')],
        ];
    }
}
