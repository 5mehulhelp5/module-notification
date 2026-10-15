# Changelog

## Unreleased

- Shared queue consumer, mailer and recipient cooldown, moved out of `commercekings/module-abandoned-cart`
  so every notification type sends the same way.
- Cooldown is optional: per message (`Message::$useCooldown`) and per shop (*Email cooldown (days)* = 0).

No public release has been tagged or published yet.
