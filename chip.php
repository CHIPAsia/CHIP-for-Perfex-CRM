<?php

/*
Module Name: CHIP for PerfexCRM
Description: Integrate CHIP with PerfexCRM
Version: 1.0.0
Requires at least: 2.3.*
*/

/**
 * Ensures that the module init file can't be accessed directly, only within the application.
 */
defined('BASEPATH') or exit('No direct script access allowed');

define('CHIP_MODULE_NAME', 'chip');

register_payment_gateway('chip_gateway', CHIP_MODULE_NAME);

/**
* Register activation module hook
*/
register_activation_hook(CHIP_MODULE_NAME, 'chip_module_activation_hook');

register_activation_hook(CHIP_MODULE_NAME, 'chip_inject_controller');

hooks()->add_filter('module_chip_action_links', 'module_chip_action_links');

function chip_module_activation_hook()
{
    $CI = &get_instance();
    require_once(__DIR__ . '/install.php');
}

function chip_inject_controller() 
{
  $chip_file = APPPATH.'/controllers/gateways/Chip.php';
  if ( file_exists($chip_file)) {
    unlink($chip_file);
  }

  $chip_controller_file = APP_MODULES_PATH . '/chip/controllers/Chip.php';

  copy( $chip_controller_file, $chip_file );
}

function module_chip_action_links($actions)
{
    $actions[] = '<a href="' . admin_url('settings?group=payment_gateways#online_payments_chip_tab') . '">' . _l('settings') . '</a>';

    return $actions;
}