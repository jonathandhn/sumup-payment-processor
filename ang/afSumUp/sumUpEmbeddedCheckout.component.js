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
        var tasks = [];
        if (!window.SumUpCard && typeof CRM.loadScript === 'function') {
          tasks.push(CRM.loadScript('https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js'));
        }
        if (!window.CiviSumUpCheckout) {
          var url = CRM.resources ? CRM.resources.getUrl('sumup-payment-processor', 'js/checkout.js') : null;
          if (url && typeof CRM.loadScript === 'function') {
            tasks.push(CRM.loadScript(url));
          }
        }
        return Promise.all(tasks);
      }

      this.active = false;
      this.completed = false;
      this.amount = '';
      this.currency = 'EUR';
      this.checkout = null;

      this.onAfformSuccess = (data) => {
        var response = data.submissionResponse;
        var checkout = response && response[0] && response[0].sumup_embedded_checkout;
        if (!checkout) {
          this.error = ts('Unable to start the secure payment form. Please try again.');
          return;
        }

        var form = this.getFormElement();
        form.find('input, select, textarea, button[type="submit"]').prop('disabled', true);
        form.addClass('crm-sumup-form--locked');

        this.active = true;
        this.completed = false;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.checkout = checkout;
        this.error = '';

        $scope.$applyAsync(() => {
          var container = $element[0].querySelector('#sumup-afform-mount-target');
          if (!container) {
            return;
          }
          container.innerHTML = '';
          ensureCheckoutSdk().then(() => {
            if (!window.CiviSumUpCheckout || typeof window.CiviSumUpCheckout.mount !== 'function') {
              throw new Error('SumUp checkout SDK unavailable');
            }
            return window.CiviSumUpCheckout.mount(container, Object.assign({}, checkout, {
              onSuccess: () => {
                this.completed = true;
                $scope.$applyAsync();
              },
            }));
          }).then(() => {
            if (typeof container.scrollIntoView === 'function') {
              container.scrollIntoView({behavior: 'smooth', block: 'nearest'});
            }
          }).catch(() => {
            this.error = ts('Unable to start the secure payment form. Please try again.');
            $scope.$applyAsync();
          });
        });
      };

      this.cancelAndUnlock = () => {
        var form = this.getFormElement();
        form.find('input, select, textarea, button[type="submit"]').prop('disabled', false);
        form.removeClass('crm-sumup-form--locked');
        this.active = false;
        this.completed = false;
        this.error = '';
        var container = $element[0].querySelector('#sumup-afform-mount-target');
        if (container) {
          container.innerHTML = '';
        }
      };
    },
  });
}(angular, CRM.$));
