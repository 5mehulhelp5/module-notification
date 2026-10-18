<?php
declare(strict_types=1);

namespace CommerceKings\Notification\Cron;

use CommerceKings\Notification\Model\AddressConsentRepository;
use CommerceKings\Notification\Model\Config;
use Magento\Store\Model\StoreManagerInterface;

/** Daily: deletes the evidence of address consents withdrawn longer ago than each store view keeps it. */
class CleanUpAddressConsents
{
    public function __construct(
        private readonly AddressConsentRepository $consents,
        private readonly Config $config,
        private readonly StoreManagerInterface $stores
    ) {
    }

    public function execute(): int
    {
        $byDays = [];
        foreach ($this->stores->getStores(true) as $store) {
            $byDays[$this->config->getAddressConsentKeepDays((int)$store->getId())][] = (int)$store->getId();
        }
        $removed = 0;
        foreach ($byDays as $days => $storeIds) {
            $removed += $this->consents->deleteWithdrawn($storeIds, (int)$days);
        }
        return $removed;
    }
}
