# Changelog

All notable changes to this project will be documented in this file.

## [3.0.0] - 2025-08-18

### 🚀 Major Laravel 10+ Compatibility Update

This is a major breaking change release that updates the package for modern Laravel versions.

### Added
- Laravel 10, 11, and 12 support
- PHP 8.1+ requirement
- Symfony Mailer integration replacing SwiftMailer
- Modern event subscription system using `EventSubscriberInterface`
- Enhanced error handling and logging

### Changed
- **BREAKING**: Completely replaced SwiftMailer with Symfony Mailer
- **BREAKING**: Updated minimum PHP requirement to 8.1
- **BREAKING**: Updated minimum Laravel requirement to 10.0
- Updated service provider to use modern Laravel mail events
- Updated event handling to use Symfony's event system
- Improved email parsing for better compatibility
- Updated PHPUnit and test dependencies to modern versions

### Removed
- SwiftMailer dependency and related code
- Support for Laravel 8 and below
- Support for PHP < 8.1
- Legacy Swift event listeners

### Fixed
- Compatibility issues with Laravel 10+ mail system
- Event handling in modern Laravel versions
- Route parameter handling in controllers
- Response facade usage updated to modern syntax

### Migration Guide

If you're upgrading from v2.x:

1. **Update Requirements**: Ensure you're running Laravel 10+ and PHP 8.1+
2. **Update Composer**: Run `composer update zainzulifqargit/mail-tracker`
3. **Clear Caches**: Run `php artisan cache:clear` and `php artisan config:clear`
4. **Test Email Tracking**: Send a test email to verify tracking still works

### Technical Details

- Replaced `\Swift_Events_SendListener` with `EventSubscriberInterface`
- Updated to use `Symfony\Component\Mailer\Event\MessageEvent`
- Enhanced email body parsing for better link and pixel injection
- Improved error handling to prevent mail sending interruption
- Updated route definitions for Laravel 10+ syntax

### Backward Compatibility

- All database schemas remain unchanged
- All route names and URLs remain the same
- All configuration options remain compatible
- All admin interface functionality preserved

---

## [2.x] - Previous Versions

Previous versions supported Laravel 8, 9 with SwiftMailer integration.