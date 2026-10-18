<?php
// Worker for StopListTest's parallel stops: waits until the shared start time, then stops one recipient.
declare(strict_types=1);

require getenv('MAGENTO_ROOT') . '/app/bootstrap.php';
$om = \Magento\Framework\App\Bootstrap::create(BP, $_SERVER)->getObjectManager();
$om->get(\Magento\Framework\App\State::class)->setAreaCode('frontend');
$stopList = $om->get(\CommerceKings\Notification\Model\StopList::class);
[, $recipient, $reason, $start] = $argv;
while (microtime(true) < (float)$start) {
    usleep(200);
}
try {
    $stopList->stop('email', $recipient, $reason);
    echo 'ok';
} catch (\Throwable $error) {
    echo get_class($error), ': ', $error->getMessage();
}
