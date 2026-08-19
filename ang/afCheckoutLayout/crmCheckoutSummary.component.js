(function (angular, CRM) {
  'use strict';

  // crm-checkout-summary — PSP-agnostic order summary component.
  //
  // Uses the same pattern as crmTaxReduction (fr.collectifidem.taxreduction):
  //   - require: { afForm: '^^afForm' } to read live form data
  //   - $scope.$watch(read, render, true) for reactive updates
  //   - crmFormChangeFilters DOM event for price-set / membership / event changes
  //
  // Outputs:
  //   - renders line items + total
  //   - fires on-total-change(total, currency) so the PSP widget knows the amount
  //   - fires on-edit() when the user clicks the Edit button

  angular.module('afCheckoutLayout').component('crmCheckoutSummary', {
    require: {
      afForm: '^^afForm',
      // Optional: when inside crm-payment-orchestrator, active state and
      // Edit action are delegated to the orchestrator automatically.
      orchestrator: '?^^crmPaymentOrchestrator'
    },
    bindings: {
      // True when the PSP payment step is active (checkout in progress).
      active: '<',
      // Callback fired whenever the computed total changes.
      // Receives { total: Number, currency: String }.
      onTotalChange: '&?',
      // Callback fired when the user clicks the Edit button.
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

        // Reactive watch: fires whenever afForm data changes (text input, select, etc.)
        $scope.$watch(readLineItems, renderSummary, true);

        // Price-set / membership / event fields fire this DOM event on the fieldset.
        // We listen on af-form because crm-checkout-summary may sit outside any
        // specific fieldset (it is in the payment slot, not in a data fieldset).
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

      // ── Line-item reader ────────────────────────────────────────────────

      function readLineItems() {
        var lines = [];
        var formEl = $element[0].closest('af-form');
        if (!formEl || !ctrl.afForm) { return lines; }

        // Contribution entities → contribution_amount price field or total_amount
        var contributions = formEl.querySelectorAll('af-entity[type="Contribution"]');
        Array.prototype.forEach.call(contributions, function (entity) {
          var entityName = entity.getAttribute('name');
          var label = entity.getAttribute('label') || ts('Contribution');
          var data = ctrl.afForm.getData(entityName);
          var fields = (data && data[0] && data[0].fields) ? data[0].fields : {};
          // CiviCRM price field path used by default New Donation afform.
          // Falls back to total_amount when a custom price set is not used.
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

        // Membership entities → fetch fee from MembershipType if type is chosen
        // (Phase 2: requires async API call — deferred)

        // Participant entities → price fields via crmFormChangeFilters
        // (Phase 2: CiviCRM price-field values parsed from event)

        return lines;
      }

      // ── Render ──────────────────────────────────────────────────────────

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

      // ── Actions ─────────────────────────────────────────────────────────

      ctrl.edit = function () {
        // Prefer orchestrator when available (orchestrator manages active state).
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

      // ── Helpers ─────────────────────────────────────────────────────────

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
