(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpSoloCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpSoloCheckout.html',
    controller: function ($scope, $element, $timeout, $window, $sce) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);
      var pollStartedAt = 0;
      var pollTimer = null;
      var countdownTimer = null;
      var maxWaitTimeMs = 300000; // 5 minutes overall session for QR / 3DS
      var terminalTimeoutMs = 60000; // 60 seconds hardware terminal window

      this.active = false;
      this.waiting = false;
      this.completed = false;
      this.failed = false;
      this.terminalExpired = false;
      this.retryingTerminal = false;
      this.terminalRetryError = '';
      this.errorMessage = '';
      this.token = '';
      this.amount = '';
      this.currency = 'EUR';
      this.readerName = '';
      this.siteCode = '';
      this.soloImageUrl = '';
      this.qrUrl = '';
      this.qrSvgTrusted = null;
      this.remainingSeconds = 60;
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
        var checkout = response && response[0] && response[0].sumup_solo_checkout;
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
        this.terminalExpired = false;
        this.retryingTerminal = false;
        this.terminalRetryError = '';
        this.errorMessage = '';
        this.token = checkout.token;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.readerName = checkout.reader_name || 'Solo';
        this.siteCode = checkout.site_code || '';
        this.soloImageUrl = checkout.solo_image_url || '';
        this.qrUrl = checkout.qr_url || '';
        this.receiptRef = checkout.client_transaction_id || '';

        if (checkout.qr_svg) {
          this.qrSvgTrusted = $sce.trustAsHtml(checkout.qr_svg);
        }

        pollStartedAt = Date.now();
        this.remainingSeconds = Math.round(terminalTimeoutMs / 1000);
        this.startCountdown();
        this.schedulePoll(1500);
        $scope.$applyAsync();
      };

      this.startCountdown = () => {
        var update = () => {
          var elapsed = Date.now() - pollStartedAt;
          var remainingTerminal = Math.max(0, Math.round((terminalTimeoutMs - elapsed) / 1000));
          var remainingSession = Math.max(0, Math.round((maxWaitTimeMs - elapsed) / 1000));
          this.remainingSeconds = remainingTerminal;
          this.terminalExpired = (remainingTerminal <= 0);

          if (remainingSession > 0 && this.waiting) {
            countdownTimer = $timeout(update, 1000);
          } else if (remainingSession <= 0 && this.waiting) {
            this.onPaymentFailure(ts('Payment session timed out. Please try again.'));
          }
        };
        countdownTimer = $timeout(update, 1000);
      };

      this.retryTerminal = () => {
        if (this.retryingTerminal) {
          return;
        }
        this.retryingTerminal = true;
        this.terminalRetryError = '';
        CRM.api4('SumupTerminalCheckout', 'retry', {
          token: this.token
        }).then((res) => {
          if (res && res[0] && res[0].token) {
            this.token = res[0].token;
          }
          if (res && res[0] && res[0].client_transaction_id) {
            this.receiptRef = res[0].client_transaction_id;
          }
          this.clearTimers();
          this.retryingTerminal = false;
          this.terminalExpired = false;
          pollStartedAt = Date.now();
          this.remainingSeconds = Math.round(terminalTimeoutMs / 1000);
          this.startCountdown();
          this.schedulePoll(1500);
          $scope.$applyAsync();
        }).catch((error) => {
          this.retryingTerminal = false;
          this.terminalExpired = true;
          this.terminalRetryError = error && error.error_message
            ? error.error_message
            : ts('Unable to resend the payment to the terminal.');
          $scope.$applyAsync();
        });
      };

      this.schedulePoll = (delay) => {
        pollTimer = $timeout(() => this.poll(), delay);
      };

      this.poll = () => {
        if (!this.waiting || !this.token) {
          return;
        }

        CRM.api4('Contribution', 'continueCheckout', {
          token: this.token,
        }).then((response) => {
          var res = (response && response[0]) ? response[0] : response;
          var status = (res && res.status) ? res.status : (response && response.status ? response.status : '');
          if (!status) {
            throw new Error('Missing SumUp checkout status');
          }
          if (res && res.token) {
            this.token = res.token;
          } else if (response && response.token) {
            this.token = response.token;
          }
          if (res && res.redirect) {
            $window.location.assign(res.redirect);
            return;
          }

          if (status === 'success' || status === 'completed') {
            this.onPaymentSuccess(res || response);
            return;
          }

          if (status === 'failed' || status === 'cancelled') {
            // Solo reader hardware timeout or cancel should mark the reader expired while keeping QR active
            this.terminalExpired = true;
          }

          if (Date.now() - pollStartedAt < maxWaitTimeMs) {
            this.schedulePoll(2000);
            return;
          }
          this.onPaymentFailure(ts('Payment session timed out. Please try again.'));
        }).catch((err) => {
          if (Date.now() - pollStartedAt < maxWaitTimeMs) {
            this.schedulePoll(3000);
            return;
          }
          this.onPaymentFailure(err && err.error_message ? err.error_message : ts('Unable to retrieve payment status.'));
        });
      };

      this.onPaymentSuccess = (response) => {
        this.clearTimers();
        this.waiting = false;
        this.completed = true;
        this.failed = false;
        $scope.$applyAsync();
      };

      this.onPaymentFailure = (msg) => {
        this.clearTimers();
        this.waiting = false;
        this.completed = false;
        this.failed = true;
        this.errorMessage = msg;
        $scope.$applyAsync();
      };

      this.retryCheckout = () => {
        this.clearTimers();
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        $window.location.reload();
      };

      this.cancelCheckout = () => {
        if (!window.confirm(ts('Cancel current terminal payment?'))) {
          return;
        }
        this.retryCheckout();
      };

      this.resetKiosk = () => {
        this.clearTimers();
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        $window.location.reload();
      };
    },
  });
}(angular, CRM.$));
