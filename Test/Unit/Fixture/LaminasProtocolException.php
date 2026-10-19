<?php
declare(strict_types=1);

namespace Laminas\Mail\Protocol\Exception;

// Laminas Mail's SMTP error (Magento 2.4.4-2.4.7), for the tests where the package is not installed
if (!class_exists(RuntimeException::class)) {
    class RuntimeException extends \RuntimeException
    {
    }
}
