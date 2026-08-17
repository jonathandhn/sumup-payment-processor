(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpQrCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpQrCheckout.html',
    controller: function ($scope, $element, $timeout, $window, $sce) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);
      var pollStartedAt = 0;
      var pollTimer = null;
      var countdownTimer = null;
      var sessionTimeoutMs = 300000; // 5 minutes

      this.active = false;
      this.waiting = false;
      this.completed = false;
      this.failed = false;
      this.errorMessage = '';
      this.token = '';
      this.amount = '';
      this.currency = 'EUR';
      this.qrUrl = '';
      this.qrSvgTrusted = null;
      this.remainingSeconds = 300;
      this.receiptRef = '';

      this.$onInit = () => this.getFormElement().on('crmFormSuccess', listener);

      this.$onDestroy = () => {
        this.getFormElement().off('crmFormSuccess', listener);
        this.clearTimers();
      };

      this.getFormElement = () => $element.closest('af-form');

      this.clearTimers = () => {
        if (pollTimer) {
          $timeout.cancel(pollTimer);
          pollTimer = null;
        }
        if (countdownTimer) {
          $timeout.cancel(countdownTimer);
          countdownTimer = null;
        }
      };

      this.onAfformSuccess = (data) => {
        var response = data.submissionResponse;
        var checkout = response && response[0] && response[0].sumup_qr_checkout;
        if (!checkout || !checkout.token) {
          return;
        }

        var form = this.getFormElement();
        form.after($element);
        form.hide();
        $element.show();

        this.active = true;
        this.waiting = true;
        this.completed = false;
        this.failed = false;
        this.errorMessage = '';
        this.token = checkout.token;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.qrUrl = checkout.qr_url || '';
        this.receiptRef = '';

        if (checkout.qr_svg) {
          this.qrSvgTrusted = $sce.trustAsHtml(checkout.qr_svg);
        }

        pollStartedAt = Date.now();
        this.remainingSeconds = Math.round(sessionTimeoutMs / 1000);
        this.startCountdown();
        this.schedulePoll(2000);
        $scope.$applyAsync();
      };

      this.startCountdown = () => {
        var update = () => {
          var elapsed = Date.now() - pollStartedAt;
          var remaining = Math.max(0, Math.round((sessionTimeoutMs - elapsed) / 1000));
          this.remainingSeconds = remaining;
          if (remaining <= 0) {
            this.waiting = false;
            this.failed = true;
            this.errorMessage = ts('The QR payment session timed out. Please try again.');
            this.clearTimers();
          } else {
            countdownTimer = $timeout(update, 1000);
          }
          $scope.$applyAsync();
        };
        countdownTimer = $timeout(update, 1000);
      };

      this.schedulePoll = (delayMs) => {
        if (!this.waiting || !this.token) {
          return;
        }
        pollTimer = $timeout(() => this.pollStatus(), delayMs);
      };

      this.pollStatus = () => {
        if (!this.waiting || !this.token) {
          return;
        }

        $.ajax({
          url: CRM.url('civicrm/checkout/continue', {token: this.token}),
          type: 'POST',
          dataType: 'json',
        }).done((res) => {
          var status = (res && res.status) ? res.status : '';
          if (status === 'success') {
            this.waiting = false;
            this.completed = true;
            this.failed = false;
            this.receiptRef = (res && res.response && res.response.receipt_ref) || '';
            this.clearTimers();
          } else if (status === 'failed') {
            this.waiting = false;
            this.failed = true;
            this.errorMessage = (res && res.message) || ts('The payment could not be processed.');
            this.clearTimers();
          } else if (status === 'cancelled') {
            this.resetKiosk();
            return;
          } else {
            this.schedulePoll(2000);
          }
          $scope.$applyAsync();
        }).fail(() => {
          // Network fluctuation: continue polling until session timeout
          this.schedulePoll(3000);
        });
      };

      this.cancelCheckout = () => {
        this.clearTimers();
        if (this.token) {
          $.ajax({
            url: CRM.url('civicrm/checkout/cancel', {token: this.token}),
            type: 'POST',
            dataType: 'json',
          });
        }
        this.resetKiosk();
      };

      this.resetKiosk = () => {
        this.clearTimers();
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        this.token = '';
        var form = this.getFormElement();
        $element.hide();
        form.show();
        $scope.$applyAsync();
      };
    },
  });
})(angular, CRM.$);
