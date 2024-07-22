<img src="./assets/logo.svg" alt="drawing" width="50"/>

# CHIP for Perfex CRM

This module adds CHIP payment method option to your [Perfex CRM](https://codecanyon.net/item/perfex-powerful-open-source-crm/14013737).

## Compatibility

Developed and tested with Perfex CRM version 3.1.5.

## Installation

* [Download zip file](https://github.com/CHIPAsia/chip-for-perfex/archive/refs/heads/main.zip).
* Log in to your Perfex admin panel and go: **Setup** -> **Modules**.
* Upload module and install.
* Activate plugin.

## Configuration

Set the **Brand ID** and **Secret Key** in the payment gateway settings.

## Notes

Installation of this module will create a file in `/controllers/gateways/Chip.php`.

However, if your setup have restrictive file permission or within ephemaral file system, you need to add this line below in your `application/config/config.php` file:

```php
$config['csrf_exclude_uris'][] = 'chip/chip/webhook';
```

Since Perfex CRM only exclude CSRF protection for URL routes that started with `gateways`, hence this plugin attempt to create a controller within `gateways`. However, if the file is missing due to ephemeral file system, it will fallback to route `chip/chip/webhook`.

Alternatively, you may copy the file from `modules/chip/controllers/Chip.php` to `controllers/gateways/Chip.php`.

## Other

Facebook: [Merchants & DEV Community](https://www.facebook.com/groups/3210496372558088)