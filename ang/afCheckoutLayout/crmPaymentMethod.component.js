(function (angular) {
  'use strict';

  // crm-payment-method — child of crm-payment-orchestrator.
  //
  // IMPORTANT: We do NOT use require: { orchestrator: '^^crmPaymentOrchestrator' }
  // because this component is transcluded into the orchestrator's slot.
  // In Angular 1, ^^ require is resolved at compile time in the OUTER scope,
  // before the transclusion is placed in the DOM — so the orchestrator ancestor
  // is not yet visible. Instead we use $postLink + DOM traversal, which runs
  // after the element is in its final DOM position inside the orchestrator.

  angular.module('afCheckoutLayout').component('crmPaymentMethod', {
    bindings: {
      key: '@',
      label: '@',
      icon: '@?',
      setData: '<?'
    },
    transclude: true,
    template:
      '<div class="crm-payment-method" ' +
          'ng-class="{\'crm-payment-method--active\': $ctrl.isActive()}">' +
        '<ng-transclude ng-show="$ctrl.isActive()"></ng-transclude>' +
      '</div>',
    controller: function ($element, $scope) {
      var ctrl = this;
      ctrl._orchestrator = null;

      ctrl.$postLink = function () {
        // Walk up the DOM to find the orchestrator — works after transclusion.
        var el = $element[0].parentElement;
        while (el) {
          var orch = angular.element(el).controller('crmPaymentOrchestrator');
          if (orch) {
            ctrl._orchestrator = orch;
            orch.register({
              key: ctrl.key,
              label: ctrl.label,
              icon: ctrl.icon || 'fa-circle-o',
              setData: ctrl.setData || null
            });
            break;
          }
          el = el.parentElement;
        }
      };

      ctrl.$onDestroy = function () {
        if (ctrl._orchestrator) {
          ctrl._orchestrator.unregister(ctrl.key);
        }
      };

      ctrl.isActive = function () {
        return ctrl._orchestrator
          ? ctrl._orchestrator.activeMethod === ctrl.key
          : true; // standalone fallback: always show
      };
    }
  });

})(angular);
