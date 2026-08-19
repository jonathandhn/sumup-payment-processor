(function (angular, CRM) {
  'use strict';

  // crm-checkout-summary — PSP-agnostic order summary component.
  //
  // Pattern: same as crmTaxReduction (fr.collectifidem.taxreduction).
  // We intentionally do NOT use require: { afForm: '^^afForm' } because
  // this component is placed inside crm-payment-orchestrator which uses
  // transclusion — Angular 1's ^^ require does not reliably cross
  // transclusion boundaries. Instead we access afForm via DOM traversal
  // (angular.element(formEl).controller('afForm')), which works regardless
  // of where the component sits in the compiled tree.

  angular.module('afCheckoutLayout').component('crmCheckoutSummary', {
    require: {
      // Optional — when inside crm-payment-orchestrator, active state and
      // Edit action are delegated to the orchestrator automatically.
      // This require DOES work because the orchestrator is a direct DOM ancestor.
      orchestrator: '?^^crmPaymentOrchestrator'
    },
    bindings: {
      active: '<?',
      onTotalChange: '&?',
      onEdit: '&?'
    },
    templateUrl: '~/afCheckoutLayout/crmCheckoutSummary.html',
    controller: function ($scope, $element) {
      var ctrl = this;
      var ts = CRM.ts('sumup-payment-processor');

      ctrl.$onInit = function () {
        ctrl.lineItems = [];
        ctrl.total = 0;
        ctrl.currency = 'EUR';
        ctrl.hasTotal = false;

        // Reactive watch — fires whenever afForm data changes.
        $scope.$watch(readLineItems, renderSummary, true);

        // Price-set / membership / event fields fire this DOM event.
        var formEl = $element[0].closest('af-form');
        if (formEl) {
          var onFilters = function () {
            $scope.$evalAsync(function () {
              renderSummary(readLineItems());
            });
          };
          formEl.addEventListener('crmFormChangeFilters', onFilters);
          $scope.$on('$destroy', function () {
            formEl.removeEventListener('crmFormChangeFilters', onFilters);
          });
        }
      };

      // ── Line-item reader ─────────────────────────────────────────────────

      function getAfForm() {
        // DOM-based access — works through transclusion boundaries.
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
            fields.total_amount ||
            null;
          var amt = parseAmount(raw);
          if (amt > 0) {
            lines.push({
              label: label,
              amount: amt,
              currency: fields.currency || 'EUR'
            });
          }
        });

        return lines;
      }

      // ── Render ───────────────────────────────────────────────────────────

      function renderSummary(lines) {
        ctrl.lineItems = lines || [];
        ctrl.total = ctrl.lineItems.reduce(function (sum, l) { return sum + l.amount; }, 0);
        ctrl.currency = ctrl.lineItems.length ? ctrl.lineItems[0].currency : 'EUR';
        ctrl.hasTotal = ctrl.total > 0;
        ctrl.formattedTotal = ctrl.hasTotal ? formatCurrency(ctrl.total, ctrl.currency) : null;
        ctrl.lineItems.forEach(function (l) {
          l.formatted = formatCurrency(l.amount, l.currency);
        });
        if (ctrl.onTotalChange) {
          ctrl.onTotalChange({ total: ctrl.total, currency: ctrl.currency });
        }
      }

      // ── Actions ──────────────────────────────────────────────────────────

      ctrl.edit = function () {
        if (ctrl.orchestrator) {
          ctrl.orchestrator.cancelActive();
        } else if (ctrl.onEdit) {
          ctrl.onEdit();
        }
      };

      ctrl.isActive = function () {
        if (ctrl.orchestrator) { return ctrl.orchestrator.active; }
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
