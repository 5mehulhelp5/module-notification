# Compatibility and verification

The first-release runtime target is Magento Open Source 2.4.9 with PHP 8.5.
The Composer PHP range is a dependency constraint, not a claim that every
Magento/PHP combination has passed runtime testing.

**Minimum: Magento Open Source 2.4.4**, enforced by `magento/framework: ^103.0.4`. Magento 2.4.0–2.4.3
run only on PHP 7.3/7.4; this package needs PHP 8.1.

Verification runs alongside `commercekings/module-abandoned-cart`, its first user, in the
CommerceKings sandbox (Composer installation, DI compilation, database status, PHPStan level 5,
Magento2 coding standard, unit and SQL integration tests with a test subscription type, and REST status checks
for both subscribe routes). Installation from distribution archives
remains pending.

The GitHub workflow checks package metadata, PHP syntax on 8.1–8.5 and Git archive contents.
