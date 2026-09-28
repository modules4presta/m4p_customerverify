{**
 * m4p_customerverify
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 *}

<div class="m4p-cv-modal" id="m4p-cv-modal" role="dialog" aria-modal="true" aria-labelledby="m4p-cv-modal-title">
    <div class="m4p-cv-modal__overlay" data-cv-close="1"></div>
    <div class="m4p-cv-modal__dialog">
        <div class="m4p-cv-modal__icon">&#10003;</div>
        <h3 class="m4p-cv-modal__title" id="m4p-cv-modal-title">
            {l s='Your account is waiting for approval' d='Modules.M4pcustomerverify.Shop'}
        </h3>
        <p class="m4p-cv-modal__text">
            {l s='Your account has been registered and is waiting for an administrator to approve it. You will get an e-mail with a link to sign in once it is active.' d='Modules.M4pcustomerverify.Shop'}
        </p>
        <button type="button" class="btn btn-primary m4p-cv-modal__btn" data-cv-close="1">
            {l s='Got it' d='Modules.M4pcustomerverify.Shop'}
        </button>
    </div>
</div>
