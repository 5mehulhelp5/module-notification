<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Api;

/** @api */
interface ProviderPoolInterface
{
    /** @return array<string, ProviderInterface> by code */
    public function getAll(): array;

    public function get(string $code): ?ProviderInterface;

    /**
     * The provider selected in the admin for the store's website and the channel. When none or an unavailable
     * one is selected: the channel's default (Magento email for email), else the first installed for the channel.
     *
     * @throws \Magento\Framework\Exception\LocalizedException when no provider serves the channel
     */
    public function getSelected(int $storeId, string $channel): ProviderInterface;
}
