(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpEmbeddedCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpEmbeddedCheckout.html',
    controller: function ($scope, $element) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);

      this.$onInit = () => {
        loadSdkScript('https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js');
        this.getFormElement().on('crmFormSuccess', listener);
      };
      this.$onDestroy = () => this.getFormElement().off('crmFormSuccess', listener);
      this.getFormElement = () => $element.closest('af-form');

      function loadSdkScript(url) {
        return new Promise(function (resolve, reject) {
          if (document.querySelector('script[src="' + url + '"]')) {
            resolve();
            return;
          }
          var script = document.createElement('script');
          script.src = url;
          script.async = true;
          script.onload = () => resolve();
          script.onerror = () => reject(new Error('Failed to load ' + url));
          document.head.appendChild(script);
        });
      }

      function ensureCheckoutSdk() {
        if (!window.SumUpCard) {
          return loadSdkScript('https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js');
        }
        return Promise.resolve();
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
          console.error('Missing sumup_embedded_checkout in submission response:', response);
          this.error = ts('Unable to start the secure payment form. Please try again.');
          return;
        }

        var form = this.getFormElement();
        var nameParts = [];
        var firstName = form.find('input[name*="first_name"]').val();
        var lastName = form.find('input[name*="last_name"]').val();
        var email = form.find('input[name*="email"], input[type="email"]').val();
        if (firstName || lastName) {
          nameParts.push([firstName, lastName].filter(Boolean).join(' '));
        }
        if (email) {
          nameParts.push(email);
        }
        this.donorSummary = nameParts.join(' · ');

        form.addClass('crm-sumup-form--compact');

        this.active = true;
        this.completed = false;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.checkout = checkout;
        this.error = '';

        ensureCheckoutSdk().then(() => {
          if (!window.CiviSumUpCheckout || typeof window.CiviSumUpCheckout.mount !== 'function') {
            throw new Error('SumUp checkout SDK unavailable');
          }
          var container = $element[0].querySelector('#sumup-afform-mount-target');
          if (!container) {
            throw new Error('Missing mount container #sumup-afform-mount-target');
          }
          container.innerHTML = '';
          return window.CiviSumUpCheckout.mount(container, checkout);
        }).then(() => {
          var container = $element[0].querySelector('#sumup-afform-mount-target');
          if (container && typeof container.scrollIntoView === 'function') {
            container.scrollIntoView({behavior: 'smooth', block: 'nearest'});
          }
        }).catch((err) => {
          console.error('SumUp Afform Mount Failure:', err);
          this.error = ts('Unable to start the secure payment form. Please try again.');
          $scope.$applyAsync();
        });
      };

      this.cancelAndUnlock = () => {
        var form = this.getFormElement();
        form.removeClass('crm-sumup-form--compact');
        form.find('button[type="submit"]').not($element.find('*')).show();
        this.active = false;
        this.completed = false;
        this.donorSummary = '';
        this.error = '';
        var container = $element[0].querySelector('#sumup-afform-mount-target');
        if (container) {
          container.innerHTML = '';
        }
      };
    },
  });
}(angular, CRM.$));
