(function (angular, CRM) {
  'use strict';

  // crm-offline-payment — display component for offline / pay-later methods.
  //
  // Before calling afform.submit(), patches the Contribution entity fields:
  //   payment_processor_id: null → CiviCRM will not call any PSP
  //   is_pay_later: 1            → records the contribution as Pending
  //   payment_instrument_id      → instrument (default 5 = EFT/bank transfer)
  //
  // After afform succeeds (crmFormSuccess), shows a confirmation message.
  //
  // Bindings:
  //   entity-name          Contribution entity name (default: auto-detected)
  //   payment-instrument-id  CiviCRM payment instrument (default: 5 = EFT)
  //   instructions         HTML shown before confirm button
  //   button-label         Label of the confirm button
  //   success-message      HTML shown after success

  angular.module('afCheckoutLayout').component('crmOfflinePayment', {
    bindings: {
      entityName: '@?',
      paymentInstrumentId: '<?',
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

        ctrl.safeInstructions = $sce.trustAsHtml(
          ctrl.instructions ||
          '<p>' + ctrl.ts('Click below to confirm your contribution. You will receive an email with the bank transfer details.') + '</p>'
        );
        ctrl.safeSuccess = $sce.trustAsHtml(
          ctrl.successMessage ||
          ctrl.ts('Thank you! Your contribution has been registered. A confirmation email with the payment details has been sent to you.')
        );
      };

      ctrl.$postLink = function () {
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
        var formEl = $element[0].closest('af-form');
        if (!formEl) { return; }

        // Patch the Contribution entity so CiviCRM skips PSP processing.
        var afForm = angular.element(formEl).controller('afForm');
        if (afForm) {
          var entityName = ctrl.entityName || resolveContributionEntity(formEl);
          var data = afForm.getData(entityName);
          if (data && data[0] && data[0].fields) {
            angular.extend(data[0].fields, {
              payment_processor_id: null,
              is_pay_later: 1,
              payment_instrument_id: ctrl.paymentInstrumentId || 5
            });
          }
        }

        // Submit the afform.
        var scope = angular.element(formEl).scope();
        if (scope && scope.afform && typeof scope.afform.submit === 'function') {
          scope.afform.submit();
        }
      };

      function resolveContributionEntity(formEl) {
        var entity = formEl.querySelector('af-entity[type="Contribution"]');
        return entity ? entity.getAttribute('name') : 'Contribution1';
      }
    }
  });

})(angular, CRM);
