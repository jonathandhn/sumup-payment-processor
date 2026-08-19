(function (angular, CRM) {
  'use strict';

  // crm-offline-payment — display component for offline / pay-later methods.
  //
  // Shows payment instructions and a submit button.
  // After the afform submits successfully (crmFormSuccess), shows a
  // confirmation message and hides the submit button.
  //
  // The submit button calls afform.submit() via the parent scope, so this
  // component MUST be used inside an af-form.
  //
  // Usage:
  //   <crm-offline-payment
  //     instructions="<p>IBAN : FR76 …</p><p>Référence : votre nom</p>"
  //     button-label="Confirmer ma réservation"
  //     success-message="Merci ! Votre contribution sera enregistrée dès réception.">
  //   </crm-offline-payment>

  angular.module('afCheckoutLayout').component('crmOfflinePayment', {
    bindings: {
      instructions: '@?',
      buttonLabel: '@?',
      successMessage: '@?'
    },
    templateUrl: '~/afCheckoutLayout/crmOfflinePayment.html',
    controller: function ($scope, $element, $sce) {
      var ctrl = this;
      ctrl.ts = CRM.ts('sumup-payment-processor');

      ctrl.$onInit = function () {
        ctrl.confirmed = false;

        // Translate defaults
        ctrl.safeInstructions = $sce.trustAsHtml(
          ctrl.instructions ||
          '<p>' + ctrl.ts('Please complete your payment via bank transfer. We will record your contribution upon receipt.') + '</p>'
        );
        ctrl.safeSuccess = $sce.trustAsHtml(
          ctrl.successMessage ||
          ctrl.ts('Thank you! Your contribution has been registered as pending. We will confirm it upon receipt of your payment.')
        );

        // Listen for general afform submit success
        var formEl = $element[0].closest('af-form');
        if (formEl) {
          var onSuccess = function () {
            $scope.$applyAsync(function () { ctrl.confirmed = true; });
          };
          formEl.addEventListener('crmFormSuccess', onSuccess);
          $scope.$on('$destroy', function () {
            formEl.removeEventListener('crmFormSuccess', onSuccess);
          });
        }
      };

      ctrl.submit = function () {
        // Access afform controller from the parent scope chain.
        // The afform template sets ctrl="afform" so the controller is on
        // the scope as "afform".
        var el = angular.element($element[0].closest('af-form'));
        var s = el.scope();
        if (s && s.afform && typeof s.afform.submit === 'function') {
          s.afform.submit();
        }
      };
    }
  });

})(angular, CRM);
