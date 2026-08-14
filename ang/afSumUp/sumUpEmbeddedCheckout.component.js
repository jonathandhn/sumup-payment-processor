(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpEmbeddedCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpEmbeddedCheckout.html',
    controller: function ($scope, $element) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);

      this.$onInit = () => this.getFormElement().on('crmFormSuccess', listener);
      this.$onDestroy = () => this.getFormElement().off('crmFormSuccess', listener);
      this.getFormElement = () => $element.closest('af-form');

      function ensureCheckoutSdk() {
        if (window.CiviSumUpCheckout) {
          return Promise.resolve();
        }
        var url = CRM.resources ? CRM.resources.getUrl('sumup-payment-processor', 'js/checkout.js') : null;
        if (url && typeof CRM.loadScript === 'function') {
          return CRM.loadScript(url);
        }
        return Promise.resolve();
      }

      this.onAfformSuccess = (data) => {
        var response = data.submissionResponse;
        var checkout = response && response[0] && response[0].sumup_embedded_checkout;
        if (!checkout) {
          this.error = ts('Unable to start the secure payment form. Please try again.');
          return;
        }

        var form = this.getFormElement();
        var container = document.createElement('div');
        container.className = 'crm-sumup-embedded-checkout';
        form.hide();
        form.after(container);
        ensureCheckoutSdk().then(() => {
          if (!window.CiviSumUpCheckout || typeof window.CiviSumUpCheckout.mount !== 'function') {
            throw new Error('SumUp checkout SDK unavailable');
          }
          return window.CiviSumUpCheckout.mount(container, checkout);
        }).catch(() => {
          this.error = ts('Unable to start the secure payment form. Please try again.');
          $scope.$applyAsync();
        });
      };
    },
  });
}(angular, CRM.$));
