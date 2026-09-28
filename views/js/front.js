/**
 * m4p_customerverify
 *
 * @author    Modules4Presta <contact@modules4presta.io>
 * @copyright 2026 Nice Code sp. z o.o. (Modules4Presta)
 * @license   https://opensource.org/licenses/MIT MIT License
 */

(function () {
    'use strict';

    var modal = document.getElementById('m4p-cv-modal');
    if (!modal) { return; }

    document.body.classList.add('m4p-cv-modal-open');

    function close() {
        if (modal.parentNode) { modal.parentNode.removeChild(modal); }
        document.body.classList.remove('m4p-cv-modal-open');
        document.removeEventListener('keydown', onKey);
    }

    function onKey(event) {
        if (event.key === 'Escape') { close(); }
    }

    modal.addEventListener('click', function (event) {
        if (event.target.getAttribute('data-cv-close') === '1') { close(); }
    });

    document.addEventListener('keydown', onKey);
})();
