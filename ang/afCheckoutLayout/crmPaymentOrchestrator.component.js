(function (angular, CRM) {
  'use strict';

  // crm-payment-orchestrator — PSP-agnostic payment method orchestrator.
  //
  // Renders method-selector tabs and manages which payment method is active.
  // Child crm-payment-method components self-register; the orchestrator shows
  // only the selected one's content.
  //
  // Also manages the "active" (payment-in-progress) state so crm-checkout-summary
  // knows when to show the Edit button.
  //
  // Usage in an afform template:
  //
  //   <crm-payment-orchestrator entity-name="Contribution1">
  //     <crm-checkout-summary></crm-checkout-summary>
  //     <crm-payment-method key="card" label="Carte" icon="fa-credit-card">
  //       <af-field name="checkout_params" defn="{label:false}"/>
  //     </crm-payment-method>
  //     <crm-payment-method key="transfer" label="Virement" icon="fa-university"
  //       set-data="{payment_processor_id: null, is_pay_later: 1}">
  //       <crm-offline-payment/>
  //     </crm-payment-method>
  //   </crm-payment-orchestrator>

  angular.module('afCheckoutLayout').component('crmPaymentOrchestrator', {
    require: {
      afForm: '^^afForm'
    },
    bindings: {
      entityName: '@?'
    },
    transclude: true,
    template:
      '<div class="crm-payment-orchestrator">' +
        // Method tabs — hidden while payment is in progress
        '<div class="crm-payment-orchestrator__tabs" ng-show="!$ctrl.active && $ctrl.methods.length > 1">' +
          '<button type="button" class="crm-payment-tab" ' +
              'ng-repeat="m in $ctrl.methods" ' +
              'ng-class="{\'crm-payment-tab--active\': $ctrl.activeMethod === m.key}" ' +
              'ng-click="$ctrl.switchMethod(m)">' +
            '<i class="crm-i {{m.icon}}" aria-hidden="true"></i> {{m.label}}' +
          '</button>' +
        '</div>' +
        '<ng-transclude></ng-transclude>' +
      '</div>',

    controller: function ($scope, $element) {
      var ctrl = this;

      ctrl.$onInit = function () {
        ctrl.methods = [];
        ctrl.activeMethod = null;
        ctrl.active = false;
        if (!ctrl.entityName) {
          ctrl.entityName = resolveContributionEntity();
        }
      };

      // ── Public API for child components (via require) ─────────────────────

      ctrl.register = function (method) {
        ctrl.methods.push(method);
        // First registered method becomes the default
        if (!ctrl.activeMethod) {
          ctrl.activeMethod = method.key;
          applyMethodData(method);
        }
      };

      ctrl.unregister = function (key) {
        ctrl.methods = ctrl.methods.filter(function (m) { return m.key !== key; });
        if (ctrl.activeMethod === key && ctrl.methods.length) {
          ctrl.switchMethod(ctrl.methods[0]);
        }
      };

      ctrl.switchMethod = function (method) {
        ctrl.activeMethod = method.key;
        applyMethodData(method);
      };

      // Called by a payment widget (e.g. af-sum-up-embedded-checkout) when
      // a checkout starts (val=true) or is cancelled (val=false).
      ctrl.setActive = function (val) {
        $scope.$applyAsync(function () {
          ctrl.active = !!val;
        });
      };

      ctrl.cancelActive = function () {
        ctrl.setActive(false);
      };

      // ── Private ───────────────────────────────────────────────────────────

      // Merge the method's setData into the live Contribution entity fields so
      // the next afform.submit() carries the right payment_processor_id etc.
      function applyMethodData(method) {
        if (!method || !method.setData || !ctrl.entityName) { return; }
        var data = ctrl.afForm.getData(ctrl.entityName);
        if (data && data[0] && data[0].fields) {
          angular.extend(data[0].fields, method.setData);
        }
      }

      function resolveContributionEntity() {
        var formEl = $element[0].closest('af-form');
        if (!formEl) { return 'Contribution1'; }
        var entity = formEl.querySelector('af-entity[type="Contribution"]');
        return entity ? entity.getAttribute('name') : 'Contribution1';
      }
    }
  });

})(angular, CRM);
