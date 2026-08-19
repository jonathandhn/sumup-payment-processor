(function (angular) {
  'use strict';

  // crm-payment-method — child of crm-payment-orchestrator.
  //
  // Registers itself with the orchestrator and shows its transcluded content
  // only when the orchestrator's activeMethod matches its key.
  //
  // Bindings:
  //   key      — unique identifier, e.g. "card", "transfer", "check"
  //   label    — tab label shown to the user
  //   icon     — Font Awesome class, e.g. "fa-credit-card"
  //   set-data — plain object merged into the Contribution entity fields
  //              when this method is selected, e.g.:
  //              {payment_processor_id: null, is_pay_later: 1, payment_instrument_id: 5}

  angular.module('afCheckoutLayout').component('crmPaymentMethod', {
    require: {
      orchestrator: '^^crmPaymentOrchestrator'
    },
    bindings: {
      key: '@',
      label: '@',
      icon: '@?',
      setData: '<?'
    },
    transclude: true,
    template:
      '<div class="crm-payment-method" ' +
          'ng-class="{\'crm-payment-method--active\': $ctrl.orchestrator.activeMethod === $ctrl.key}">' +
        '<ng-transclude ng-show="$ctrl.orchestrator.activeMethod === $ctrl.key"></ng-transclude>' +
      '</div>',
    controller: function () {
      var ctrl = this;

      ctrl.$onInit = function () {
        ctrl.orchestrator.register({
          key: ctrl.key,
          label: ctrl.label,
          icon: ctrl.icon || 'fa-circle-o',
          setData: ctrl.setData || null
        });
      };

      ctrl.$onDestroy = function () {
        ctrl.orchestrator.unregister(ctrl.key);
      };
    }
  });

})(angular);
