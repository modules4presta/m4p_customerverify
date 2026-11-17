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
 * Adds "Only the group after approval" (on) and "Account active before approval" (off), the hook that tells
 * an activation from other edits, the table of one-time home page notices and the hook that loads their script.
 * Saved settings are kept.
 */
function upgrade_module_1_3_0(M4p_CustomerVerify $module): bool
{
    return (Configuration::hasKey(M4p_CustomerVerify::CFG_GROUP_ONLY)
            || Configuration::updateValue(M4p_CustomerVerify::CFG_GROUP_ONLY, 1))
        && (Configuration::hasKey(M4p_CustomerVerify::CFG_KEEP_ACTIVE)
            || Configuration::updateValue(M4p_CustomerVerify::CFG_KEEP_ACTIVE, 0))
        && $module->registerHook('actionObjectCustomerUpdateBefore')
        && $module->installNoticeTable()
        && $module->registerHook('actionFrontControllerSetMedia');
}
