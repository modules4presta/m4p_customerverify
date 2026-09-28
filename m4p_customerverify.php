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

class M4p_CustomerVerify extends Module
{
    public const CFG_ENABLED = 'M4P_CV_ENABLED';
    public const CFG_ADMIN_EMAIL = 'M4P_CV_ADMIN_EMAIL';
    public const COOKIE_FLAG = 'm4p_cv_pending';

    /** Seconds the pending notice stays armed, so a theme without the banner hook cannot pin the visitor to the home page. */
    private const NOTICE_TTL = 300;

    public function __construct()
    {
        $this->name = 'm4p_customerverify';
        $this->tab = 'administration';
        $this->version = '1.0.0';
        $this->author = 'Modules4Presta';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];

        parent::__construct();

        $this->displayName = $this->trans('Customer account verification', [], 'Modules.M4pcustomerverify.Admin');
        $this->description = $this->trans('Keeps new accounts inactive until an administrator approves them, and tells both sides by e-mail.', [], 'Modules.M4pcustomerverify.Admin');
    }

    public function install(): bool
    {
        Configuration::updateValue(self::CFG_ENABLED, 1);
        Configuration::updateValue(self::CFG_ADMIN_EMAIL, '');

        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('actionFrontControllerSetMedia')
            && $this->registerHook('displayAfterBodyOpeningTag');
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(self::CFG_ENABLED);
        Configuration::deleteByName(self::CFG_ADMIN_EMAIL);

        return $this->uninstallDb() && parent::uninstall();
    }

    protected function installDb(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'm4p_pending_verification` (
                `id_customer` INT UNSIGNED NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;'
        );
    }

    protected function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute('DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'm4p_pending_verification`');
    }

    private function isVerificationEnabled(): bool
    {
        return (bool) Configuration::get(self::CFG_ENABLED);
    }

    /* ---------------------------------------------------------------------
     * Registration: account deactivation + notifications
     * ------------------------------------------------------------------- */

    public function hookActionCustomerAccountAdd(array $params): void
    {
        if (!$this->isVerificationEnabled() || empty($params['newCustomer'])) {
            return;
        }

        /** @var Customer $customer */
        $customer = $params['newCustomer'];
        if ($customer->is_guest) {
            return;
        }

        // 1) Account inactive until approved.
        $customer->active = 0;
        $customer->update();

        // 2) Mark as pending verification.
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'm4p_pending_verification` (`id_customer`, `date_add`)
            VALUES (' . (int) $customer->id . ', NOW())'
        );

        // 3) Notify the admin.
        $this->notifyAdmin($customer);

        // 4) PrestaShop auto-logged the customer in - log them back out.
        if (Validate::isLoadedObject($this->context->customer) && (int) $this->context->customer->id === (int) $customer->id) {
            $this->context->customer->logout();
        }

        // 5) Flag to show the message after redirecting to the home page.
        $this->context->cookie->{self::COOKIE_FLAG} = time();
        $this->context->cookie->write();
    }

    /* ---------------------------------------------------------------------
     * Admin activation => email to the customer
     * ------------------------------------------------------------------- */

    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        if (!$this->isVerificationEnabled() || empty($params['object'])) {
            return;
        }

        /** @var Customer $customer */
        $customer = $params['object'];
        if (!Validate::isLoadedObject($customer) || !(int) $customer->active) {
            return;
        }

        // Only if the account was marked as pending (i.e. it was just activated).
        $isPending = (int) Db::getInstance()->getValue(
            'SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'm4p_pending_verification` WHERE `id_customer` = ' . (int) $customer->id
        );
        if (!$isPending) {
            return;
        }

        Db::getInstance()->execute(
            'DELETE FROM `' . _DB_PREFIX_ . 'm4p_pending_verification` WHERE `id_customer` = ' . (int) $customer->id
        );

        $this->notifyCustomerActivated($customer);
    }

    /* ---------------------------------------------------------------------
     * Front: redirect to the home page + message banner
     * ------------------------------------------------------------------- */

    public function hookActionFrontControllerSetMedia(): void
    {
        if (!$this->noticeIsPending()) {
            return;
        }

        if (($this->context->controller->php_self ?? '') !== 'index') {
            Tools::redirect($this->context->link->getPageLink('index'));
        }

        $this->context->controller->registerStylesheet(
            'm4p-customerverify',
            'modules/' . $this->name . '/views/css/front.css',
            ['media' => 'all', 'priority' => 150]
        );
        $this->context->controller->registerJavascript(
            'm4p-customerverify',
            'modules/' . $this->name . '/views/js/front.js',
            ['position' => 'bottom', 'priority' => 150]
        );
    }

    public function hookDisplayAfterBodyOpeningTag(array $params): string
    {
        if (!$this->noticeIsPending() || ($this->context->controller->php_self ?? '') !== 'index') {
            return '';
        }

        $this->clearNotice();

        return $this->display(__FILE__, 'views/templates/hook/pending_banner.tpl');
    }

    private function noticeIsPending(): bool
    {
        $flag = (int) ($this->context->cookie->{self::COOKIE_FLAG} ?? 0);
        if (!$flag) {
            return false;
        }

        if (time() - $flag > self::NOTICE_TTL) {
            $this->clearNotice();

            return false;
        }

        return true;
    }

    private function clearNotice(): void
    {
        unset($this->context->cookie->{self::COOKIE_FLAG});
        $this->context->cookie->write();
    }

    /* ---------------------------------------------------------------------
     * Emails
     * ------------------------------------------------------------------- */

    private function notifyAdmin(Customer $customer): void
    {
        $to = (string) Configuration::get(self::CFG_ADMIN_EMAIL);
        if (!Validate::isEmail($to)) {
            $to = (string) Configuration::get('PS_SHOP_EMAIL');
        }
        if (!Validate::isEmail($to)) {
            return;
        }

        $idLang = (int) Configuration::get('PS_LANG_DEFAULT');
        $shopName = (string) Configuration::get('PS_SHOP_NAME');

        Mail::Send(
            $idLang,
            'new_account_verify',
            $this->trans('A new account is waiting for approval', [], 'Modules.M4pcustomerverify.Admin') . ' - ' . $shopName,
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{id_customer}' => (int) $customer->id,
                '{shop_name}' => $shopName,
            ],
            $to,
            null,
            null,
            null,
            null,
            null,
            dirname(__FILE__) . '/mails/',
            false,
            (int) $this->context->shop->id
        );
    }

    private function notifyCustomerActivated(Customer $customer): void
    {
        if (!Validate::isEmail($customer->email)) {
            return;
        }

        $idLang = (int) ($customer->id_lang ?: Configuration::get('PS_LANG_DEFAULT'));
        $shopName = (string) Configuration::get('PS_SHOP_NAME');
        $loginUrl = $this->context->link->getPageLink('authentication', true);

        Mail::Send(
            $idLang,
            'account_activated',
            $this->trans('Your account has been activated', [], 'Modules.M4pcustomerverify.Admin') . ' - ' . $shopName,
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{login_url}' => $loginUrl,
                '{shop_name}' => $shopName,
            ],
            $customer->email,
            $customer->firstname . ' ' . $customer->lastname,
            null,
            null,
            null,
            null,
            dirname(__FILE__) . '/mails/',
            false,
            (int) $this->context->shop->id
        );
    }

    /* ---------------------------------------------------------------------
     * Konfiguracja
     * ------------------------------------------------------------------- */

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitM4pCv')) {
            Configuration::updateValue(self::CFG_ENABLED, (int) (bool) Tools::getValue(self::CFG_ENABLED));
            $email = trim((string) Tools::getValue(self::CFG_ADMIN_EMAIL));
            if ($email !== '' && !Validate::isEmail($email)) {
                $output .= $this->displayError($this->trans('That e-mail address is not valid, so nothing was saved.', [], 'Modules.M4pcustomerverify.Admin'));
            } else {
                Configuration::updateValue(self::CFG_ADMIN_EMAIL, $email);
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.M4pcustomerverify.Admin'));
            }
        }

        // Address notifications actually go to (falls back to the shop email).
        $adminEmail = (string) Configuration::get(self::CFG_ADMIN_EMAIL);
        $effective = Validate::isEmail($adminEmail) ? $adminEmail : (string) Configuration::get('PS_SHOP_EMAIL');

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->trans('Verification settings', [], 'Modules.M4pcustomerverify.Admin'), 'icon' => 'icon-cogs'],
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Hold new accounts for approval', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_ENABLED,
                        'is_bool' => true,
                        'values' => [
                            ['id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.M4pcustomerverify.Admin')],
                            ['id' => 'off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.M4pcustomerverify.Admin')],
                        ],
                    ],
                    [
                        'type' => 'text',
                        'label' => $this->trans('Notification e-mail', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_ADMIN_EMAIL,
                        'desc' => $this->trans(
                            'Where the notice about an account waiting for approval is sent. Empty means the shop e-mail. Notices currently go to %email%.',
                            ['%email%' => $effective],
                            'Modules.M4pcustomerverify.Admin'
                        ),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Modules.M4pcustomerverify.Admin'), 'name' => 'submitM4pCv'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->submit_action = 'submitM4pCv';
        $helper->fields_value = [
            self::CFG_ENABLED => (int) Configuration::get(self::CFG_ENABLED),
            self::CFG_ADMIN_EMAIL => (string) Configuration::get(self::CFG_ADMIN_EMAIL),
        ];

        return $output . $helper->generateForm([$fields_form]);
    }
}
