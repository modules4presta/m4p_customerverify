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
    public const CFG_BUSINESS_ONLY = 'M4P_CV_BUSINESS_ONLY';
    public const CFG_GROUP = 'M4P_CV_GROUP';
    public const CFG_GROUP_ONLY = 'M4P_CV_GROUP_ONLY';
    public const CFG_KEEP_ACTIVE = 'M4P_CV_KEEP_ACTIVE';
    public const FIELD_BUSINESS = 'm4p_cv_business';
    public const FIELD_COMPANY = 'company';
    public const FIELD_TAX_NUMBER = 'siret';
    public const NOTICE_REQUEST = 'request';
    public const NOTICE_PRICE_LIST = 'price_list';
    public const ADMIN_CONTROLLER = 'AdminM4pCustomerVerify';

    /** @var array<int, bool> customer id => account was inactive before the update being saved */
    private $wasInactive = [];

    public function __construct()
    {
        $this->name = 'm4p_customerverify';
        $this->tab = 'administration';
        $this->version = '1.3.0';
        $this->author = 'Modules4Presta';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.6.0', 'max' => _PS_VERSION_];
        $this->tabs = [
            [
                'class_name' => self::ADMIN_CONTROLLER,
                'parent_class_name' => 'AdminParentCustomer',
                'name' => ['en' => 'Accounts to approve', 'pl' => 'Konta do akceptacji'],
                'visible' => true,
            ],
        ];

        parent::__construct();

        $this->displayName = $this->trans('Customer account verification', [], 'Modules.M4pcustomerverify.Admin');
        $this->description = $this->trans('Keeps new accounts inactive until an administrator approves them, and tells both sides by e-mail.', [], 'Modules.M4pcustomerverify.Admin');
    }

    public function install(): bool
    {
        Configuration::updateValue(self::CFG_ENABLED, 1);
        Configuration::updateValue(self::CFG_ADMIN_EMAIL, '');
        Configuration::updateValue(self::CFG_BUSINESS_ONLY, 1);
        Configuration::updateValue(self::CFG_GROUP, 0);
        Configuration::updateValue(self::CFG_GROUP_ONLY, 1);
        Configuration::updateValue(self::CFG_KEEP_ACTIVE, 0);

        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionObjectCustomerUpdateBefore')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('additionalCustomerFormFields')
            && $this->registerHook('validateCustomerFormFields')
            && $this->registerHook('actionFrontControllerSetMedia');
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(self::CFG_ENABLED);
        Configuration::deleteByName(self::CFG_ADMIN_EMAIL);
        Configuration::deleteByName(self::CFG_BUSINESS_ONLY);
        Configuration::deleteByName(self::CFG_GROUP);
        Configuration::deleteByName(self::CFG_GROUP_ONLY);
        Configuration::deleteByName(self::CFG_KEEP_ACTIVE);

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
        ) && $this->installNoticeTable();
    }

    public function installNoticeTable(): bool
    {
        return (bool) Db::getInstance()->execute(
            'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'm4p_customerverify_notice` (
                `id_customer` INT UNSIGNED NOT NULL,
                `notice` VARCHAR(32) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_customer`, `notice`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;'
        );
    }

    protected function uninstallDb(): bool
    {
        return (bool) Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'm4p_pending_verification`, `' . _DB_PREFIX_ . 'm4p_customerverify_notice`'
        );
    }

    private function isVerificationEnabled(): bool
    {
        return (bool) Configuration::get(self::CFG_ENABLED);
    }

    private function isBusinessOnly(): bool
    {
        return (bool) Configuration::get(self::CFG_BUSINESS_ONLY);
    }

    private function keepsAccountActive(): bool
    {
        return (bool) Configuration::get(self::CFG_KEEP_ACTIVE);
    }

    /**
     * Adds the account type choice and the company fields to the registration form only; checkout and the
     * identity page keep their forms. With PS_B2B_ENABLE the core form already has company and siret.
     *
     * @return FormField[]
     */
    public function hookAdditionalCustomerFormFields(array $params): array
    {
        if (!$this->isVerificationEnabled() || !$this->isBusinessOnly()
            || ($this->context->controller->php_self ?? '') !== 'registration'
        ) {
            return [];
        }

        $fields = [
            (new FormField())
                ->setName(self::FIELD_BUSINESS)
                ->setType('radio-buttons')
                ->setLabel($this->trans('Account type', [], 'Modules.M4pcustomerverify.Shop'))
                ->addAvailableValue('0', $this->trans('Private person', [], 'Modules.M4pcustomerverify.Shop'))
                ->addAvailableValue('1', $this->keepsAccountActive()
                    ? $this->trans('Company (regular prices until the account is approved)', [], 'Modules.M4pcustomerverify.Shop')
                    : $this->trans('Company', [], 'Modules.M4pcustomerverify.Shop'))
                ->setValue(Tools::getValue(self::FIELD_BUSINESS) ? '1' : '0'),
        ];

        if (!Configuration::get('PS_B2B_ENABLE')) {
            $fields[] = (new FormField())
                ->setName(self::FIELD_COMPANY)
                ->setType('text')
                ->setMaxLength(255)
                ->setLabel($this->trans('Company name', [], 'Modules.M4pcustomerverify.Shop'));
            $fields[] = (new FormField())
                ->setName(self::FIELD_TAX_NUMBER)
                ->setType('text')
                ->setMaxLength(32)
                ->setLabel($this->trans('NIP or EU VAT number', [], 'Modules.M4pcustomerverify.Shop'));
        }

        return $fields;
    }

    /**
     * Company name and tax number are required only for a company account; a private account drops them.
     * A Polish NIP is checked by its checksum, an EU VAT number in VIES (an unavailable VIES does not block).
     *
     * @return FormField[]
     */
    public function hookValidateCustomerFormFields(array $params): array
    {
        $fields = [];
        foreach ($params['fields'] ?? [] as $field) {
            if ($field instanceof FormField) {
                $fields[$field->getName()] = $field;
            }
        }
        if (!isset($fields[self::FIELD_COMPANY], $fields[self::FIELD_TAX_NUMBER])) {
            return $params['fields'] ?? [];
        }

        $company = $fields[self::FIELD_COMPANY];
        $taxNumber = $fields[self::FIELD_TAX_NUMBER];
        if (!isset($fields[self::FIELD_BUSINESS]) || !$fields[self::FIELD_BUSINESS]->getValue()) {
            $company->setValue('');
            $taxNumber->setValue('');

            return $params['fields'];
        }

        $name = trim((string) $company->getValue());
        $company->setValue($name);
        if ($name === '') {
            $company->addError($this->trans('Enter the company name.', [], 'Modules.M4pcustomerverify.Shop'));
        } elseif (!Validate::isGenericName($name)) {
            $company->addError($this->trans('The company name contains characters that are not allowed.', [], 'Modules.M4pcustomerverify.Shop'));
        }

        $number = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $taxNumber->getValue()));
        $error = $this->taxNumberError($number);
        if ($error !== '') {
            $taxNumber->addError($error);
        } else {
            $taxNumber->setValue(preg_match('/^PL(\d{10})$/', $number, $match) ? $match[1] : $number);
        }

        return $params['fields'];
    }

    /**
     * @return string error for the customer, '' when the number is accepted
     */
    private function taxNumberError(string $number): string
    {
        if ($number === '') {
            return $this->trans('Enter the NIP or the EU VAT number of the company.', [], 'Modules.M4pcustomerverify.Shop');
        }
        if (preg_match('/^(PL)?\d+$/', $number)) {
            return self::isValidNip((string) preg_replace('/^PL/', '', $number))
                ? ''
                : $this->trans('This NIP is not valid. Check the digits.', [], 'Modules.M4pcustomerverify.Shop');
        }
        if (!preg_match('/^([A-Z]{2})[A-Z0-9]{2,13}$/', $number, $match)) {
            return $this->trans('Enter a 10-digit NIP or an EU VAT number with the country code, e.g. DE123456789.', [], 'Modules.M4pcustomerverify.Shop');
        }

        $viesCheck = _PS_MODULE_DIR_ . 'm4p_viesintegration/classes/M4pViesCheck.php';
        if (!Module::isEnabled('m4p_viesintegration') || !is_file($viesCheck)) {
            return '';
        }
        require_once $viesCheck;
        if (!in_array($match[1], M4pViesCheck::EU_PREFIXES, true)) {
            return '';
        }

        return M4pViesCheck::check($number) === M4pViesCheck::INVALID
            ? $this->trans('VIES does not confirm this EU VAT number. Check it and try again.', [], 'Modules.M4pcustomerverify.Shop')
            : '';
    }

    /**
     * Polish NIP: ten digits, the last one is the weighted checksum of the first nine.
     */
    public static function isValidNip(string $nip): bool
    {
        if (!preg_match('/^\d{10}$/', $nip)) {
            return false;
        }
        $sum = 0;
        foreach ([6, 5, 7, 2, 3, 4, 5, 6, 7] as $i => $weight) {
            $sum += $weight * (int) $nip[$i];
        }

        return $sum % 11 === (int) $nip[9];
    }

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

        $isBusiness = ($this->context->controller->php_self ?? '') === 'registration'
            && (bool) Tools::getValue(self::FIELD_BUSINESS);
        if ($this->isBusinessOnly() && !$isBusiness) {
            return;
        }

        if (!$this->keepsAccountActive()) {
            $customer->active = false;
            $customer->update();
        }

        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'm4p_pending_verification` (`id_customer`, `date_add`)
            VALUES (' . (int) $customer->id . ', NOW())'
        );

        $this->notifyAdmin($customer);

        if ($this->keepsAccountActive()) {
            $this->addNotice((int) $customer->id, self::NOTICE_REQUEST);
            $this->redirectToHome();

            return;
        }

        if (Validate::isLoadedObject($this->context->customer) && (int) $this->context->customer->id === (int) $customer->id) {
            $this->context->customer->logout();
        }

        // The home page is publicly cached by LiteSpeed, so the notice goes to the uncached login page.
        $controller = $this->context->controller;
        if ($controller instanceof FrontController && !$controller->ajax) {
            $controller->info[] = $this->trans(
                'Your account has been registered and is waiting for an administrator to approve it. You will get an e-mail with a link to sign in once it is active.',
                [],
                'Modules.M4pcustomerverify.Shop'
            );
            $controller->redirectWithNotifications($this->context->link->getPageLink('authentication', true));
        }
    }

    /**
     * Sends the customer, still signed in, from the registration page to the home page, where the notice script shows
     * the request notice. Checkout keeps its own flow.
     */
    private function redirectToHome(): void
    {
        $controller = $this->context->controller;
        if (!$controller instanceof FrontController || $controller->ajax || ($controller->php_self ?? '') !== 'registration') {
            return;
        }

        Tools::redirect($this->context->link->getPageLink('index', true));
    }

    /**
     * Home page script that asks for the signed-in customer's notices; the page itself stays cacheable.
     */
    public function hookActionFrontControllerSetMedia(): void
    {
        if (!$this->isVerificationEnabled() || ($this->context->controller->php_self ?? '') !== 'index') {
            return;
        }

        Media::addJsDef(['m4pCvNoticeUrl' => $this->context->link->getModuleLink($this->name, 'notice', [], true)]);
        $this->context->controller->registerJavascript(
            'm4p-cv-notice',
            'modules/' . $this->name . '/views/js/notice.js',
            ['position' => 'bottom', 'priority' => 200, 'attributes' => 'defer', 'version' => (string) filemtime(__DIR__ . '/views/js/notice.js')]
        );
    }

    private function addNotice(int $idCustomer, string $notice): void
    {
        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'm4p_customerverify_notice` (`id_customer`, `notice`, `date_add`)
            VALUES (' . $idCustomer . ', \'' . pSQL($notice) . '\', NOW())'
        );
    }

    private function removeNotice(int $idCustomer, string $notice): void
    {
        Db::getInstance()->delete('m4p_customerverify_notice', '`id_customer` = ' . $idCustomer . ' AND `notice` = \'' . pSQL($notice) . '\'');
    }

    /**
     * Returns the customer's notices as texts and removes them, so each one is shown once.
     *
     * @return string[]
     */
    public function takeNotices(int $idCustomer): array
    {
        $codes = array_column(Db::getInstance()->executeS(
            'SELECT `notice` FROM `' . _DB_PREFIX_ . 'm4p_customerverify_notice` WHERE `id_customer` = ' . $idCustomer . ' ORDER BY `date_add`'
        ) ?: [], 'notice');
        if (!$codes) {
            return [];
        }
        Db::getInstance()->delete('m4p_customerverify_notice', '`id_customer` = ' . $idCustomer);

        $texts = [];
        foreach ($codes as $code) {
            if ($code === self::NOTICE_REQUEST) {
                $texts[] = $this->trans(
                    'Your account is active. We have received your company account request – once it is approved, you will get an e-mail and see company prices.',
                    [],
                    'Modules.M4pcustomerverify.Shop'
                );
            } elseif ($code === self::NOTICE_PRICE_LIST) {
                $texts[] = $this->trans('Your company account has been approved and its price list updated.', [], 'Modules.M4pcustomerverify.Shop');
            }
        }

        return $texts;
    }

    public function hookActionObjectCustomerUpdateBefore(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!$customer instanceof Customer || !(int) $customer->id || !$this->isPending((int) $customer->id)) {
            return;
        }

        $this->wasInactive[(int) $customer->id] = !(bool) Db::getInstance()->getValue(
            'SELECT `active` FROM `' . _DB_PREFIX_ . 'customer` WHERE `id_customer` = ' . (int) $customer->id
        );
    }

    /**
     * Switching "Enable" on for a pending account in Customers counts as approval; other edits of a pending account do not.
     */
    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        $customer = $params['object'] ?? null;
        if (!$customer instanceof Customer || !Validate::isLoadedObject($customer)) {
            return;
        }

        $wasInactive = $this->wasInactive[(int) $customer->id] ?? false;
        unset($this->wasInactive[(int) $customer->id]);
        if (!$wasInactive || !(int) $customer->active || !$this->isPending((int) $customer->id)) {
            return;
        }

        $this->completeApproval($customer, true, (int) Configuration::get(self::CFG_GROUP));
    }

    /**
     * Assigns the group (0 keeps the current ones), queues the home page notice and mails the customer: "activated" for a held
     * account, "price list updated" when the group changed, otherwise "approved".
     */
    private function completeApproval(Customer $customer, bool $wasActivated, int $idGroup): void
    {
        $this->removePending((int) $customer->id);
        $this->removeNotice((int) $customer->id, self::NOTICE_REQUEST);

        $groupChanged = $this->assignGroup($customer, $idGroup);
        if ($groupChanged) {
            $this->addNotice((int) $customer->id, self::NOTICE_PRICE_LIST);
        }

        if ($wasActivated) {
            $this->sendCustomerMail($customer, 'account_activated', $this->trans('Your account has been activated', [], 'Modules.M4pcustomerverify.Admin'));
        } elseif ($groupChanged) {
            $this->sendCustomerMail($customer, 'price_list_updated', $this->trans('The price list of your account has been updated', [], 'Modules.M4pcustomerverify.Admin'));
        } else {
            $this->sendCustomerMail($customer, 'account_approved', $this->trans('Your company account has been approved', [], 'Modules.M4pcustomerverify.Admin'));
        }
    }

    private function assignGroup(Customer $customer, int $idGroup): bool
    {
        if (!$idGroup || !Validate::isLoadedObject(new Group($idGroup))) {
            return false;
        }

        if (Configuration::get(self::CFG_GROUP_ONLY)) {
            $customer->updateGroup([$idGroup]);
        } else {
            $customer->addGroups([$idGroup]);
        }
        // A direct update keeps Customer::update() from re-syncing groups from the admin form.
        Db::getInstance()->update('customer', ['id_default_group' => $idGroup], 'id_customer = ' . (int) $customer->id);
        $customer->id_default_group = $idGroup;

        return true;
    }

    public function isPending(int $idCustomer): bool
    {
        return (bool) Db::getInstance()->getValue(
            'SELECT 1 FROM `' . _DB_PREFIX_ . 'm4p_pending_verification` WHERE `id_customer` = ' . $idCustomer
        );
    }

    private function removePending(int $idCustomer): void
    {
        Db::getInstance()->delete('m4p_pending_verification', '`id_customer` = ' . $idCustomer);
    }

    /**
     * Approves a pending account: activates it when it was held, assigns the group and mails the customer.
     *
     * @param int $idGroup group chosen in the queue, 0 keeps the current groups
     */
    public function approve(Customer $customer, int $idGroup): bool
    {
        if ($idGroup && !array_key_exists($idGroup, $this->getApprovalGroups())) {
            return false;
        }

        $wasActivated = !(int) $customer->active;
        if ($wasActivated) {
            // Removed first, so the update hook does not approve the account a second time.
            $this->removePending((int) $customer->id);
            $customer->active = true;
            if (!$customer->update()) {
                return false;
            }
        }

        $this->completeApproval($customer, $wasActivated, $idGroup);

        return true;
    }

    /**
     * Rejects the company request: the account is activated as a regular retail account and the customer is told by e-mail.
     */
    public function reject(Customer $customer): bool
    {
        $this->removePending((int) $customer->id);
        $this->removeNotice((int) $customer->id, self::NOTICE_REQUEST);
        if (!(int) $customer->active) {
            $customer->active = true;
            if (!$customer->update()) {
                return false;
            }
        }

        $this->sendCustomerMail($customer, 'account_rejected', $this->trans('Your company account request', [], 'Modules.M4pcustomerverify.Admin'));

        return true;
    }

    /**
     * Groups an approved account can get: all except the visitor and guest groups.
     *
     * @return array<int, string> group id => name
     */
    public function getApprovalGroups(): array
    {
        $excluded = [(int) Configuration::get('PS_UNIDENTIFIED_GROUP'), (int) Configuration::get('PS_GUEST_GROUP')];
        $groups = [];
        foreach (Group::getGroups((int) $this->context->language->id) as $group) {
            if (!in_array((int) $group['id_group'], $excluded, true)) {
                $groups[(int) $group['id_group']] = (string) $group['name'];
            }
        }

        return $groups;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getPendingAccounts(): array
    {
        return Db::getInstance()->executeS(
            'SELECT c.`id_customer`, c.`firstname`, c.`lastname`, c.`email`, c.`company`, c.`siret`, p.`date_add`
            FROM `' . _DB_PREFIX_ . 'm4p_pending_verification` p
            INNER JOIN `' . _DB_PREFIX_ . 'customer` c ON c.`id_customer` = p.`id_customer`
            WHERE c.`deleted` = 0
            ORDER BY p.`date_add` ASC'
        ) ?: [];
    }

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
                '{company}' => $customer->company ?: '-',
                '{tax_number}' => $customer->siret ?: '-',
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

    private function sendCustomerMail(Customer $customer, string $template, string $subject): void
    {
        if (!Validate::isEmail($customer->email)) {
            return;
        }

        $idLang = (int) ($customer->id_lang ?: Configuration::get('PS_LANG_DEFAULT'));
        $shopName = (string) Configuration::get('PS_SHOP_NAME');

        Mail::Send(
            $idLang,
            $template,
            $subject . ' - ' . $shopName,
            [
                '{firstname}' => $customer->firstname,
                '{lastname}' => $customer->lastname,
                '{email}' => $customer->email,
                '{login_url}' => $this->context->link->getPageLink('authentication', true),
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

    public function getContent(): string
    {
        $output = '';

        if (Tools::isSubmit('submitM4pCv')) {
            $email = trim((string) Tools::getValue(self::CFG_ADMIN_EMAIL));
            if ($email !== '' && !Validate::isEmail($email)) {
                $output .= $this->displayError($this->trans('That e-mail address is not valid, so nothing was saved.', [], 'Modules.M4pcustomerverify.Admin'));
            } else {
                Configuration::updateValue(self::CFG_ENABLED, (int) (bool) Tools::getValue(self::CFG_ENABLED));
                Configuration::updateValue(self::CFG_BUSINESS_ONLY, (int) (bool) Tools::getValue(self::CFG_BUSINESS_ONLY));
                Configuration::updateValue(self::CFG_GROUP, (int) Tools::getValue(self::CFG_GROUP));
                Configuration::updateValue(self::CFG_GROUP_ONLY, (int) (bool) Tools::getValue(self::CFG_GROUP_ONLY));
                Configuration::updateValue(self::CFG_KEEP_ACTIVE, (int) (bool) Tools::getValue(self::CFG_KEEP_ACTIVE));
                Configuration::updateValue(self::CFG_ADMIN_EMAIL, $email);
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Modules.M4pcustomerverify.Admin'));
            }
        }

        $adminEmail = (string) Configuration::get(self::CFG_ADMIN_EMAIL);
        $effective = Validate::isEmail($adminEmail) ? $adminEmail : (string) Configuration::get('PS_SHOP_EMAIL');
        $switchValues = [
            ['id' => 'on', 'value' => 1, 'label' => $this->trans('Yes', [], 'Modules.M4pcustomerverify.Admin')],
            ['id' => 'off', 'value' => 0, 'label' => $this->trans('No', [], 'Modules.M4pcustomerverify.Admin')],
        ];
        $groups = array_merge(
            [['id_group' => 0, 'name' => $this->trans('Do not change the group', [], 'Modules.M4pcustomerverify.Admin')]],
            Group::getGroups((int) $this->context->language->id)
        );

        $fields_form = [
            'form' => [
                'legend' => ['title' => $this->trans('Verification settings', [], 'Modules.M4pcustomerverify.Admin'), 'icon' => 'icon-cogs'],
                'description' => $this->trans(
                    'Accounts waiting for approval are listed in Customers > Accounts to approve.',
                    [],
                    'Modules.M4pcustomerverify.Admin'
                ),
                'input' => [
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Company account verification', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_ENABLED,
                        'is_bool' => true,
                        'values' => $switchValues,
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Only company accounts', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_BUSINESS_ONLY,
                        'is_bool' => true,
                        'values' => $switchValues,
                        'desc' => $this->trans(
                            'Adds the account type choice with company name and tax number to the registration form and holds only company accounts. Off holds every new account.',
                            [],
                            'Modules.M4pcustomerverify.Admin'
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Account active before approval', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_KEEP_ACTIVE,
                        'is_bool' => true,
                        'values' => $switchValues,
                        'desc' => $this->trans(
                            'On: the customer can sign in and shop right after registering, with the default customer group. Approval only changes the group. Off: the account stays inactive until approved.',
                            [],
                            'Modules.M4pcustomerverify.Admin'
                        ),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Group after approval', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_GROUP,
                        'options' => ['query' => $groups, 'id' => 'id_group', 'name' => 'name'],
                        'desc' => $this->trans(
                            'Added to the customer and set as the default group when the account is approved.',
                            [],
                            'Modules.M4pcustomerverify.Admin'
                        ),
                    ],
                    [
                        'type' => 'switch',
                        'label' => $this->trans('Only the group after approval', [], 'Modules.M4pcustomerverify.Admin'),
                        'name' => self::CFG_GROUP_ONLY,
                        'is_bool' => true,
                        'values' => $switchValues,
                        'desc' => $this->trans(
                            'On: the approved account keeps only this group, so its prices and any full-page cache follow it. Off: the group is added next to the ones the account already has.',
                            [],
                            'Modules.M4pcustomerverify.Admin'
                        ),
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
            self::CFG_BUSINESS_ONLY => (int) Configuration::get(self::CFG_BUSINESS_ONLY),
            self::CFG_GROUP => (int) Configuration::get(self::CFG_GROUP),
            self::CFG_GROUP_ONLY => (int) Configuration::get(self::CFG_GROUP_ONLY),
            self::CFG_KEEP_ACTIVE => (int) Configuration::get(self::CFG_KEEP_ACTIVE),
            self::CFG_ADMIN_EMAIL => (string) Configuration::get(self::CFG_ADMIN_EMAIL),
        ];

        return $output . $helper->generateForm([$fields_form]);
    }
}
