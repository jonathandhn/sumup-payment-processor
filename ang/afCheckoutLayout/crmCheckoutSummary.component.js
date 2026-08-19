(function (angular, CRM) {
  'use strict';

  // crm-checkout-summary — PSP-agnostic order summary component.
  //
  // Pattern: same as crmTaxReduction (fr.collectifidem.taxreduction).
  //
  // We avoid require: { afForm/orchestrator } because this component is
  // transcluded. Angular 1's ^^ require resolves at compile time in the
  // outer scope — before transclusion places the element in the DOM.
  // Instead we use DOM traversal in $postLink (final DOM position guaranteed).

  angular.module('afCheckoutLayout').component('crmCheckoutSummary', {
    bindings: {
      active: '<?',
      onTotalChange: '&?',
      onEdit: '&?'
    },
    templateUrl: '~/afSumUp/crmCheckoutSummary.html',
    controller: function ($scope, $element) {
      var ctrl = this;
      var ts = CRM.ts('sumup-payment-processor');
      var _orchestrator = null;

      ctrl.$onInit = function () {
        ctrl.lineItems = [];
        ctrl.total = 0;
        ctrl.currency = 'EUR';
        ctrl.hasTotal = false;
        // Watch is set up here; getAfForm() is called lazily at each digest.
        $scope.$watch(readLineItems, renderSummary, true);
      };

      ctrl.$postLink = function () {
        // DOM is in its final position after transclusion — safe to traverse.
        var formEl = $element[0].closest('af-form');
        if (formEl) {
          var onFilters = function () {
            $scope.$evalAsync(function () { renderSummary(readLineItems()); });
          };
          formEl.addEventListener('crmFormChangeFilters', onFilters);
          $scope.$on('$destroy', function () {
            formEl.removeEventListener('crmFormChangeFilters', onFilters);
          });
        }

        // Find the orchestrator by walking up the DOM.
        var el = $element[0].parentElement;
        while (el) {
          var orch = angular.element(el).controller('crmPaymentOrchestrator');
          if (orch) { _orchestrator = orch; break; }
          el = el.parentElement;
        }

        // Trigger an initial render now that we have the orchestrator.
        $scope.$evalAsync(function () { renderSummary(readLineItems()); });
      };

      // ── Line-item reader ─────────────────────────────────────────────────

      function getAfForm() {
        var formEl = $element[0].closest('af-form');
        return formEl ? angular.element(formEl).controller('afForm') : null;
      }

      function readLineItems() {
        var lines = [];
        var formEl = $element[0].closest('af-form');
        var afForm = getAfForm();
        if (!formEl || !afForm) { return lines; }

        var contributions = formEl.querySelectorAll('af-entity[type="Contribution"]');
        Array.prototype.forEach.call(contributions, function (entity) {
          var entityName = entity.getAttribute('name');
          var label = entity.getAttribute('label') || ts('Contribution');
          var data = afForm.getData(entityName);
          var fields = (data && data[0] && data[0].fields) ? data[0].fields : {};
          var raw = fields['default_contribution_amount.contribution_amount'] ||
            fields.total_amount || null;
          var amt = parseAmount(raw);
          if (amt > 0) {
            lines.push({ label: label, amount: amt, currency: fields.currency || 'EUR' });
          }
        });
        return lines;
      }

      // ── Render ───────────────────────────────────────────────────────────

      function renderSummary(lines) {
        ctrl.lineItems = lines || [];
        ctrl.total = ctrl.lineItems.reduce(function (s, l) { return s + l.amount; }, 0);
        ctrl.currency = ctrl.lineItems.length ? ctrl.lineItems[0].currency : 'EUR';
        ctrl.hasTotal = ctrl.total > 0;
        ctrl.formattedTotal = ctrl.hasTotal ? formatCurrency(ctrl.total, ctrl.currency) : null;
        ctrl.lineItems.forEach(function (l) { l.formatted = formatCurrency(l.amount, l.currency); });
        if (ctrl.onTotalChange) { ctrl.onTotalChange({ total: ctrl.total, currency: ctrl.currency }); }
      }

      // ── Helpers: find the SumUp checkout controller via DOM ──────────────

      function getCheckoutCtrl() {
        var formEl = $element[0].closest('af-form');
        if (!formEl) { return null; }
        var checkoutEl = formEl.querySelector('af-sum-up-embedded-checkout');
        return checkoutEl
          ? angular.element(checkoutEl).controller('afSumUpEmbeddedCheckout')
          : null;
      }

      // ── Actions ──────────────────────────────────────────────────────────

      ctrl.edit = function () {
        if (_orchestrator) { _orchestrator.cancelActive(); return; }
        var checkout = getCheckoutCtrl();
        if (checkout) { checkout.cancelAndUnlock(); return; }
        if (ctrl.onEdit) { ctrl.onEdit(); }
      };

      ctrl.isActive = function () {
        if (_orchestrator) { return !!_orchestrator.active; }
        var checkout = getCheckoutCtrl();
        if (checkout) { return !!checkout.active; }
        return !!ctrl.active;
      };

      // ── Helpers ──────────────────────────────────────────────────────────

      function parseAmount(value) {
        if (value === null || value === undefined || value === '') { return 0; }
        return parseFloat(String(value).replace(/\s/g, '').replace(',', '.')) || 0;
      }

      function formatCurrency(value, currency) {
        if (window.Intl && window.Intl.NumberFormat) {
          return new window.Intl.NumberFormat(
            document.documentElement.lang || 'fr-FR',
            { style: 'currency', currency: currency || 'EUR' }
          ).format(value);
        }
        return CRM.formatMoney(value) + '\u00a0' + (currency || 'EUR');
      }
    }
  });

})(angular, CRM);
