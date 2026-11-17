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
 * @property M4p_CustomerVerify $module
 */
class AdminM4pCustomerVerifyController extends ModuleAdminController
{
    public function __construct()
    {
        $this->bootstrap = true;
        parent::__construct();
    }

    public function postProcess(): void
    {
        if (Tools::isSubmit('cv_approve')) {
            $action = 'approve';
            $idCustomer = (int) Tools::getValue('cv_approve');
        } elseif (Tools::isSubmit('cv_reject')) {
            $action = 'reject';
            $idCustomer = (int) Tools::getValue('cv_reject');
        } else {
            return;
        }

        $customer = new Customer($idCustomer);
        if (!Validate::isLoadedObject($customer) || !$this->module->isPending((int) $customer->id)) {
            $this->errors[] = $this->module->getTranslator()->trans('This account is no longer waiting for approval.', [], 'Modules.M4pcustomerverify.Admin');

            return;
        }

        $groups = Tools::getValue('cv_group');
        $idGroup = is_array($groups) ? (int) ($groups[$idCustomer] ?? 0) : 0;
        $done = $action === 'approve' ? $this->module->approve($customer, $idGroup) : $this->module->reject($customer);
        if (!$done) {
            $this->errors[] = $this->module->getTranslator()->trans('The account could not be saved.', [], 'Modules.M4pcustomerverify.Admin');

            return;
        }

        $this->confirmations[] = $action === 'approve'
            ? $this->module->getTranslator()->trans('Account approved, the customer has been notified.', [], 'Modules.M4pcustomerverify.Admin')
            : $this->module->getTranslator()->trans('Request rejected, the account is active as a regular one and the customer has been notified.', [], 'Modules.M4pcustomerverify.Admin');
    }

    public function initContent(): void
    {
        parent::initContent();

        $trans = $this->module->getTranslator();
        $fields = [
            'id_customer' => ['title' => 'ID', 'type' => 'int', 'class' => 'fixed-width-xs'],
            'firstname' => ['title' => $trans->trans('First name', [], 'Admin.Global')],
            'lastname' => ['title' => $trans->trans('Last name', [], 'Admin.Global')],
            'email' => ['title' => $trans->trans('Email', [], 'Admin.Global')],
            'company' => ['title' => $trans->trans('Company', [], 'Admin.Global')],
            'siret' => ['title' => $trans->trans('Tax number', [], 'Modules.M4pcustomerverify.Admin')],
            'date_add' => ['title' => $trans->trans('Registered', [], 'Modules.M4pcustomerverify.Admin'), 'type' => 'datetime'],
            'approval_group' => [
                'title' => $trans->trans('Group after approval', [], 'Modules.M4pcustomerverify.Admin'),
                'callback' => 'displayGroupSelect',
                'search' => false,
                'orderby' => false,
            ],
        ];

        $rows = array_map(static function (array $row): array {
            return $row + ['approval_group' => ''];
        }, $this->module->getPendingAccounts());

        $helper = new HelperList();
        $helper->shopLinkType = '';
        $helper->simple_header = true;
        $helper->identifier = 'id_customer';
        $helper->actions = ['approve', 'reject', 'customer'];
        $helper->show_toolbar = false;
        $helper->no_link = true;
        $helper->listTotal = count($rows);
        $helper->title = $trans->trans('Company accounts waiting for approval', [], 'Modules.M4pcustomerverify.Admin');
        $helper->table = 'm4p_pending_verification';
        $helper->token = $this->token;
        $helper->currentIndex = self::$currentIndex;

        $this->content .= $helper->generateList($rows, $fields);
        $this->context->smarty->assign('content', $this->content);
    }

    /**
     * Group picker for one pending account, preselected with the group from the module settings.
     */
    public function displayGroupSelect(string $value, array $row): string
    {
        $idCustomer = (int) $row['id_customer'];
        $selected = (int) Configuration::get(M4p_CustomerVerify::CFG_GROUP);
        $options = [0 => $this->module->getTranslator()->trans('Do not change the group', [], 'Modules.M4pcustomerverify.Admin')]
            + $this->module->getApprovalGroups();

        $html = '<select name="cv_group[' . $idCustomer . ']" class="fixed-width-xl" data-customer="' . $idCustomer . '">';
        foreach ($options as $idGroup => $name) {
            $html .= '<option value="' . (int) $idGroup . '"' . ((int) $idGroup === $selected ? ' selected' : '') . '>'
                . htmlspecialchars($name, ENT_QUOTES) . '</option>';
        }

        return $html . '</select>';
    }

    public function displayApproveLink(?string $token, int $id): string
    {
        return $this->actionButton('cv_approve', $id, 'icon-check', $this->module->getTranslator()->trans('Approve', [], 'Modules.M4pcustomerverify.Admin'));
    }

    public function displayRejectLink(?string $token, int $id): string
    {
        return $this->actionButton('cv_reject', $id, 'icon-remove', $this->module->getTranslator()->trans('Reject', [], 'Modules.M4pcustomerverify.Admin'));
    }

    public function displayCustomerLink(?string $token, int $id): string
    {
        $url = $this->context->link->getAdminLink('AdminCustomers', true, ['route' => 'admin_customers_view', 'customerId' => $id]);

        return '<a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES) . '"><i class="icon-search"></i> '
            . htmlspecialchars($this->module->getTranslator()->trans('View', [], 'Admin.Actions'), ENT_QUOTES) . '</a>';
    }

    /**
     * Submits the list form, so the group picked in the same row travels with the decision.
     */
    private function actionButton(string $name, int $id, string $icon, string $label): string
    {
        // "name" before "class": HelperList's first-action regex rewrites the markup unless it finds "a…class="…btn…"".
        return '<button type="submit" name="' . $name . '" value="' . $id . '" class="btn btn-default"><i class="' . $icon . '"></i> '
            . htmlspecialchars($label, ENT_QUOTES) . '</button>';
    }
}
