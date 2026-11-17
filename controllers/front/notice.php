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
 * Returns the signed-in customer's one-time notices as JSON for the home page script.
 *
 * @property M4p_CustomerVerify $module
 */
class M4p_CustomerVerifyNoticeModuleFrontController extends ModuleFrontController
{
    public function displayAjax(): void
    {
        header('Cache-Control: no-store');
        header('X-LiteSpeed-Cache-Control: no-cache');

        $notices = [];
        if ($this->context->customer->isLogged()
            && Tools::getIsset('token') && Tools::getValue('token') === Tools::getToken(false)
        ) {
            $notices = $this->module->takeNotices((int) $this->context->customer->id);
        }

        $this->ajaxRender((string) json_encode(['notices' => $notices]));
    }
}
