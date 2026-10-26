<?php

declare(strict_types=1);

/**
 * m4p_customerverify
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Adds the Customers > Accounts to approve tab and the registration form hook, drops the home page
 * notice hooks. "Only company accounts" starts off, so every new account is still held as in 1.0.0.
 */
function upgrade_module_1_1_0(M4p_CustomerVerify $module): bool
{
    foreach ([M4p_CustomerVerify::CFG_BUSINESS_ONLY => 0, M4p_CustomerVerify::CFG_GROUP => 0] as $key => $value) {
        if (!Configuration::hasKey($key)) {
            Configuration::updateValue($key, $value);
        }
    }

    $module->unregisterHook('actionFrontControllerSetMedia');
    $module->unregisterHook('displayAfterBodyOpeningTag');

    if (!Tab::getIdFromClassName(M4p_CustomerVerify::ADMIN_CONTROLLER)) {
        $tab = new Tab();
        $tab->class_name = M4p_CustomerVerify::ADMIN_CONTROLLER;
        $tab->module = $module->name;
        $tab->id_parent = (int) Tab::getIdFromClassName('AdminParentCustomer');
        $tab->active = true;
        foreach (Language::getLanguages(false) as $language) {
            $tab->name[(int) $language['id_lang']] = $language['iso_code'] === 'pl' ? 'Konta do akceptacji' : 'Accounts to approve';
        }
        if (!$tab->add()) {
            return false;
        }
    }

    return $module->registerHook('additionalCustomerFormFields');
}
