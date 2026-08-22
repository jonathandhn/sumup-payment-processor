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

        // Find the orchestrator: first try DOM ancestors (transcluded case),
        // then fall back to a sibling within the same af-form (bento case).
        var el = $element[0].parentElement;
        while (el) {
          var orch = angular.element(el).controller('crmPaymentOrchestrator');
          if (orch) { _orchestrator = orch; break; }
          if (el.tagName === 'AF-FORM') { break; }
          el = el.parentElement;
        }
        if (!_orchestrator) {
          var formEl2 = $element[0].closest('af-form');
          if (formEl2) {
            var orchEl = formEl2.querySelector('crm-payment-orchestrator');
            if (orchEl) {
              _orchestrator = angular.element(orchEl).controller('crmPaymentOrchestrator');
            }
          }
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

        // Scan all entity types that can produce payable line items in Afform
        var entities = formEl.querySelectorAll('af-entity[type]');
        Array.prototype.forEach.call(entities, function (entity) {
          var entityType = entity.getAttribute('type');
          var entityName = entity.getAttribute('name');
          var entityLabel = entity.getAttribute('label');
          var data = afForm.getData(entityName);
          if (!data || !data[0] || !data[0].fields) { return; }
          var fields = data[0].fields;
          var currency = fields.currency || 'EUR';

          if (entityType === 'Contribution') {
            // 1. Direct amount field (standard donation / contribution input).
            // Do NOT fallback to total_amount here: total_amount is the consolidated order total,
            // not an individual donation line item.
            var donationRaw = fields['default_contribution_amount.contribution_amount'];
            if (donationRaw !== undefined && donationRaw !== null && donationRaw !== '') {
              var baseAmount = parseAmount(donationRaw);
              if (baseAmount > 0) {
                lines.push({
                  label: entityLabel || ts('Contribution'),
                  amount: baseAmount,
                  currency: currency,
                  type: 'contribution'
                });
              }
            }

            // 2. Scan for additional price set fields on Contribution
            Object.keys(fields).forEach(function (key) {
              if (key === 'total_amount' || key === 'default_contribution_amount.contribution_amount' || key === 'checkout_params' || key === 'checkout_option' || key === 'financial_type_id') {
                return;
              }
              var resolved = resolveFieldItem(formEl, key, fields[key], entityLabel || ts('Donation Option'), currency, 'contribution');
              if (resolved && resolved.amount > 0) {
                lines.push(resolved);
              }
            });
          }
 else if (entityType === 'Participant') {
            // Participant / Event ticket options & fees
            var participantLabel = entityLabel || ts('Event Registration');
            var feeAmt = parseAmount(fields.fee_amount);
            if (feeAmt > 0) {
              lines.push({
                label: participantLabel,
                amount: feeAmt,
                currency: currency,
                type: 'participant'
              });
            }

            // Scan price set options on participant (e.g. participant_fields.ticket_option)
            Object.keys(fields).forEach(function (key) {
              if (key === 'fee_amount' || key === 'event_id' || key === 'contact_id' || key === 'status_id' || key === 'role_id') {
                return;
              }
              var resolved = resolveFieldItem(formEl, key, fields[key], participantLabel, currency, 'participant');
              if (resolved && resolved.amount > 0) {
                lines.push(resolved);
              }
            });
          } else if (entityType === 'Membership') {
            // Membership fee
            var membershipLabel = entityLabel || ts('Membership');
            var memAmt = parseAmount(fields.fee_amount);
            if (memAmt > 0) {
              lines.push({
                label: membershipLabel,
                amount: memAmt,
                currency: currency,
                type: 'membership'
              });
            }

            // Scan membership price fields
            Object.keys(fields).forEach(function (key) {
              if (key === 'fee_amount' || key === 'total_amount' || key === 'membership_type_id' || key === 'contact_id') {
                return;
              }
              var resolved = resolveFieldItem(formEl, key, fields[key], membershipLabel, currency, 'membership');
              if (resolved && resolved.amount > 0) {
                lines.push(resolved);
              }
            });
          }
        });

        return lines;
      }

      var IGNORED_FIELDS = [
        'id', 'contact_id', 'status_id', 'event_id', 'membership_type_id',
        'financial_type_id', 'payment_processor_id', 'contribution_recur_id',
        'is_test', 'is_primary', 'currency', 'source', 'checkout_option',
        'checkout_params', 'total_amount', 'fee_amount', 'role_id'
      ];

      function isPriceField(key) {
        if (!key || IGNORED_FIELDS.indexOf(key) !== -1) { return false; }
        // Price fields in Afform always use dot notation (e.g. price_set.field_name)
        return key.indexOf('.') !== -1;
      }

      function resolveFieldItem(formEl, fieldKey, rawValue, fallbackLabel, currency, itemType) {
        if (!isPriceField(fieldKey) || rawValue === null || rawValue === undefined || rawValue === '') {
          return null;
        }

        var fieldEl = formEl.querySelector('af-field[name="' + fieldKey + '"]');
        var fieldCtrl = fieldEl ? angular.element(fieldEl).controller('afField') : null;
        var defn = fieldCtrl ? fieldCtrl.defn : null;
        var fieldLabel = (defn && defn.label) ? defn.label : formatFieldLabel(fieldKey, fallbackLabel);

        // Check if the field definition has select/radio options
        if (defn && Array.isArray(defn.options) && defn.options.length > 0) {
          var option = null;
          for (var i = 0; i < defn.options.length; i++) {
            if (String(defn.options[i].id) === String(rawValue)) {
              option = defn.options[i];
              break;
            }
          }

          if (option) {
            var optAmount = (option.amount !== undefined && option.amount !== null)
              ? parseAmount(option.amount)
              : parseAmountFromText(option.label);

            if (optAmount <= 0) { return null; }

            var optLabel = option.label || '';
            var fullLabel = fieldLabel;
            if (optLabel && optLabel !== fieldLabel) {
              fullLabel = fieldLabel + ' : ' + optLabel;
            }

            return {
              label: fullLabel,
              amount: optAmount,
              currency: currency,
              type: itemType
            };
          }
        }

        // Direct numeric input on a price field (e.g. custom amount)
        var directAmt = parseAmount(rawValue);
        if (directAmt > 0) {
          return {
            label: fieldLabel,
            amount: directAmt,
            currency: currency,
            type: itemType
          };
        }

        return null;
      }

      function parseAmountFromText(text) {
        if (!text) { return 0; }
        // Match patterns like "2,00 €" or "2.00 EUR" or "2 €"
        var match = String(text).match(/([0-9]+(?:[.,][0-9]{1,2})?)\s*(?:€|\$|EUR|USD)/i);
        if (match && match[1]) {
          return parseAmount(match[1]);
        }
        return parseAmount(text);
      }

      function formatFieldLabel(key, defaultLabel) {
        if (!key) { return defaultLabel; }
        var parts = key.split('.');
        var rawName = parts[parts.length - 1];
        if (!rawName) { return defaultLabel; }
        return rawName.replace(/_/g, ' ');
      }

      // ── Render ───────────────────────────────────────────────────────────

      function renderSummary(lines) {
        ctrl.lineItems = lines || [];
        ctrl.total = ctrl.lineItems.reduce(function (s, l) { return s + l.amount; }, 0);
        ctrl.currency = ctrl.lineItems.length ? ctrl.lineItems[0].currency : 'EUR';
        ctrl.hasTotal = ctrl.total > 0;
        ctrl.formattedTotal = ctrl.hasTotal ? formatCurrency(ctrl.total, ctrl.currency) : null;
        ctrl.lineItems.forEach(function (l) { l.formatted = formatCurrency(l.amount, l.currency); });

        // Synchronize consolidated order total into the Contribution entity data
        // so that CiviCRM submits the full consolidated amount for payment creation.
        syncTotalToContribution(ctrl.total);

        if (ctrl.onTotalChange) { ctrl.onTotalChange({ total: ctrl.total, currency: ctrl.currency }); }
      }

      function syncTotalToContribution(totalAmount) {
        if (!totalAmount || totalAmount <= 0) { return; }
        var formEl = $element[0].closest('af-form');
        var afForm = getAfForm();
        if (!formEl || !afForm) { return; }

        var contributionEntity = formEl.querySelector('af-entity[type="Contribution"]');
        if (!contributionEntity) { return; }
        var entityName = contributionEntity.getAttribute('name');
        var data = afForm.getData(entityName);
        if (data && data[0] && data[0].fields) {
          data[0].fields.total_amount = totalAmount;
          if (!data[0].fields.checkout_params || typeof data[0].fields.checkout_params !== 'object') {
            data[0].fields.checkout_params = {};
          }
          data[0].fields.checkout_params.order_total = totalAmount;
        }
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
