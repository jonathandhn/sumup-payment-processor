(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpSoloCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpSoloCheckout.html',
    controller: function ($scope, $element, $timeout, $window) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);
      var pollStartedAt = 0;
      var pollTimer;

      this.$onInit = () => this.getFormElement().on('crmFormSuccess', listener);
      this.$onDestroy = () => {
        this.getFormElement().off('crmFormSuccess', listener);
        if (pollTimer) {
          $timeout.cancel(pollTimer);
        }
      };
      this.getFormElement = () => $element.closest('af-form');

      this.onAfformSuccess = (data) => {
        var response = data.submissionResponse;
        var checkout = response && response[0] && response[0].sumup_solo_checkout;
        if (!checkout || !checkout.token) {
          this.error = ts('Unable to start the terminal payment. Please try again.');
          return;
        }

        this.getFormElement().hide();
        this.message = checkout.message;
        this.waiting = true;
        this.token = checkout.token;
        pollStartedAt = Date.now();
        this.schedulePoll(1000);
      };

      this.schedulePoll = (delay) => {
        pollTimer = $timeout(() => this.poll(), delay);
      };

      this.poll = () => CRM.api4('Contribution', 'continueCheckout', {
        token: this.token,
      }).then((response) => {
        response = response && response[0] ? response[0] : response;
        if (!response || !response.status) {
          throw new Error('Missing SumUp checkout status');
        }
        if (response.token) {
          this.token = response.token;
        }
        if (response.redirect) {
          $window.location.assign(response.redirect);
          return;
        }
        this.message = response.message || this.message;
        if (response.status === 'pending') {
          if (Date.now() - pollStartedAt < 180000) {
            this.schedulePoll(2000);
            return;
          }
          this.waiting = false;
          this.message = ts('The terminal payment is still pending. Its final status will be updated automatically.');
          return;
        }
        this.waiting = false;
      }).catch(() => {
        if (Date.now() - pollStartedAt < 180000) {
          this.schedulePoll(3000);
          return;
        }
        this.waiting = false;
        this.error = ts('Unable to retrieve the terminal payment status. Its final status will be updated automatically.');
      });
    },
  });
}(angular, CRM.$));
