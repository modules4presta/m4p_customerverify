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
        $action = Tools::getValue('cv_action');
        if (!in_array($action, ['approve', 'reject'], true)) {
            return;
        }

        $customer = new Customer((int) Tools::getValue('id_customer'));
        if (!Validate::isLoadedObject($customer) || !$this->module->isPending((int) $customer->id)) {
            $this->errors[] = $this->module->getTranslator()->trans('This account is no longer waiting for approval.', [], 'Modules.M4pcustomerverify.Admin');

            return;
        }

        $done = $action === 'approve' ? $this->module->approve($customer) : $this->module->reject($customer);
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
        ];

        $rows = $this->module->getPendingAccounts();

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

    public function displayApproveLink(?string $token, int $id): string
    {
        return $this->actionLink('approve', $id, 'icon-check', $this->module->getTranslator()->trans('Approve', [], 'Modules.M4pcustomerverify.Admin'));
    }

    public function displayRejectLink(?string $token, int $id): string
    {
        return $this->actionLink('reject', $id, 'icon-remove', $this->module->getTranslator()->trans('Reject', [], 'Modules.M4pcustomerverify.Admin'));
    }

    public function displayCustomerLink(?string $token, int $id): string
    {
        $url = $this->context->link->getAdminLink('AdminCustomers', true, ['route' => 'admin_customers_view', 'customerId' => $id]);

        return '<a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES) . '"><i class="icon-search"></i> '
            . htmlspecialchars($this->module->getTranslator()->trans('View', [], 'Admin.Actions'), ENT_QUOTES) . '</a>';
    }

    private function actionLink(string $action, int $id, string $icon, string $label): string
    {
        $url = self::$currentIndex . '&token=' . $this->token . '&cv_action=' . $action . '&id_customer=' . $id;

        return '<a class="btn btn-default" href="' . htmlspecialchars($url, ENT_QUOTES) . '"><i class="' . $icon . '"></i> '
            . htmlspecialchars($label, ENT_QUOTES) . '</a>';
    }
}
