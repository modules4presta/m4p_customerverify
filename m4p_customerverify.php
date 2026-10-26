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
    public const FIELD_BUSINESS = 'm4p_cv_business';
    public const FIELD_COMPANY = 'company';
    public const FIELD_TAX_NUMBER = 'siret';
    public const ADMIN_CONTROLLER = 'AdminM4pCustomerVerify';

    public function __construct()
    {
        $this->name = 'm4p_customerverify';
        $this->tab = 'administration';
        $this->version = '1.2.0';
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

        return parent::install()
            && $this->installDb()
            && $this->registerHook('actionCustomerAccountAdd')
            && $this->registerHook('actionObjectCustomerUpdateAfter')
            && $this->registerHook('additionalCustomerFormFields')
            && $this->registerHook('validateCustomerFormFields');
    }

    public function uninstall(): bool
    {
        Configuration::deleteByName(self::CFG_ENABLED);
        Configuration::deleteByName(self::CFG_ADMIN_EMAIL);
        Configuration::deleteByName(self::CFG_BUSINESS_ONLY);
        Configuration::deleteByName(self::CFG_GROUP);

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

    private function isBusinessOnly(): bool
    {
        return (bool) Configuration::get(self::CFG_BUSINESS_ONLY);
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
                ->addAvailableValue('1', $this->trans('Company', [], 'Modules.M4pcustomerverify.Shop'))
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

        $customer->active = false;
        $customer->update();

        Db::getInstance()->execute(
            'INSERT IGNORE INTO `' . _DB_PREFIX_ . 'm4p_pending_verification` (`id_customer`, `date_add`)
            VALUES (' . (int) $customer->id . ', NOW())'
        );

        $this->notifyAdmin($customer);

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
     * Activating a pending account (queue or the "Enable" switch in Customers) assigns the configured group and mails the customer.
     */
    public function hookActionObjectCustomerUpdateAfter(array $params): void
    {
        if (empty($params['object'])) {
            return;
        }

        /** @var Customer $customer */
        $customer = $params['object'];
        if (!Validate::isLoadedObject($customer) || !(int) $customer->active || !$this->isPending((int) $customer->id)) {
            return;
        }

        $this->removePending((int) $customer->id);

        $idGroup = (int) Configuration::get(self::CFG_GROUP);
        if ($idGroup && Validate::isLoadedObject(new Group($idGroup))) {
            $customer->addGroups([$idGroup]);
            // A direct update keeps Customer::update() from re-syncing groups from the admin form.
            Db::getInstance()->update('customer', ['id_default_group' => $idGroup], 'id_customer = ' . (int) $customer->id);
            $customer->id_default_group = $idGroup;
        }

        $this->sendCustomerMail($customer, 'account_activated', $this->trans('Your account has been activated', [], 'Modules.M4pcustomerverify.Admin'));
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
     * Approves a pending account; the group and the e-mail follow from hookActionObjectCustomerUpdateAfter.
     */
    public function approve(Customer $customer): bool
    {
        $customer->active = true;

        return (bool) $customer->update();
    }

    /**
     * Rejects the company request: the account is activated as a regular retail account and the customer is told by e-mail.
     */
    public function reject(Customer $customer): bool
    {
        $this->removePending((int) $customer->id);
        $customer->active = true;
        if (!$customer->update()) {
            return false;
        }

        $this->sendCustomerMail($customer, 'account_rejected', $this->trans('Your company account request', [], 'Modules.M4pcustomerverify.Admin'));

        return true;
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
                        'label' => $this->trans('Hold new accounts for approval', [], 'Modules.M4pcustomerverify.Admin'),
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
            self::CFG_ADMIN_EMAIL => (string) Configuration::get(self::CFG_ADMIN_EMAIL),
        ];

        return $output . $helper->generateForm([$fields_form]);
    }
}
