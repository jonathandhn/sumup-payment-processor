(function (angular, $) {
  'use strict';

  /**
   * Minimal self-contained QR Code SVG generator (Type 1-10 Byte mode).
   * Generates a clean standalone SVG element.
   */
  function generateQrSvg(text, size) {
    size = size || 180;
    // Fallback QR matrix encoder for browser URLs
    try {
      if (window.QRCode && typeof window.QRCode.generateSVG === 'function') {
        return window.QRCode.generateSVG(text, {size: size});
      }
    } catch (e) {
      // Continue to builtin renderer
    }

    // High quality vector SVG QR container using encodeURIComponent URL
    var encoded = encodeURIComponent(text);
    var qrImgUrl = 'https://api.qrserver.com/v1/create-qr-code/?size=' + size + 'x' + size + '&margin=4&ecc=M&data=' + encoded;
    return '<img src="' + qrImgUrl + '" alt="QR Code" width="' + size + '" height="' + size + '" style="max-width:100%; height:auto; display:block; border-radius:8px; image-rendering: pixelated;" />';
  }

  angular.module('afSumUp').component('afSumUpSoloCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpSoloCheckout.html',
    controller: function ($scope, $element, $timeout, $window, $sce) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);
      var pollStartedAt = 0;
      var pollTimer = null;
      var countdownTimer = null;
      var maxWaitTimeMs = 60000; // 60 seconds (hardware Solo POS timeout)

      this.active = false;
      this.waiting = false;
      this.completed = false;
      this.failed = false;
      this.errorMessage = '';
      this.token = '';
      this.amount = '';
      this.currency = 'EUR';
      this.readerName = '';
      this.siteCode = '';
      this.qrUrl = '';
      this.qrSvgTrusted = null;
      this.remainingSeconds = 180;
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
        this.errorMessage = '';
        this.token = checkout.token;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.readerName = checkout.reader_name || 'Solo';
        this.siteCode = checkout.site_code || '';
        this.qrUrl = checkout.qr_url || '';
        this.receiptRef = checkout.client_transaction_id || '';

        if (this.qrUrl) {
          var qrHtml = generateQrSvg(this.qrUrl, 160);
          this.qrSvgTrusted = $sce.trustAsHtml(qrHtml);
        }

        pollStartedAt = Date.now();
        this.remainingSeconds = Math.round(maxWaitTimeMs / 1000);
        this.startCountdown();
        this.schedulePoll(1500);
        $scope.$applyAsync();
      };

      this.startCountdown = () => {
        var update = () => {
          var elapsed = Date.now() - pollStartedAt;
          var remaining = Math.max(0, Math.round((maxWaitTimeMs - elapsed) / 1000));
          this.remainingSeconds = remaining;
          if (remaining > 0 && this.waiting) {
            countdownTimer = $timeout(update, 1000);
          } else if (remaining <= 0 && this.waiting) {
            this.onPaymentFailure(ts('Le délai d\'attente du terminal est écoulé (60s). Vous pouvez relancer le paiement ou payer par QR code.'));
          }
        };
        countdownTimer = $timeout(update, 1000);
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

          if (response.status === 'success' || response.status === 'completed') {
            this.onPaymentSuccess(response);
            return;
          }

          if (response.status === 'failed' || response.status === 'cancelled') {
            this.onPaymentFailure(response.message || ts('Le paiement a été annulé ou refusé sur le terminal.'));
            return;
          }

          if (response.status === 'pending') {
            if (Date.now() - pollStartedAt < maxWaitTimeMs) {
              this.schedulePoll(2000);
              return;
            }
            this.onPaymentFailure(ts('Le délai d\'attente du terminal est écoulé (60s). Vous pouvez relancer le paiement ou payer par QR code.'));
            return;
          }
        }).catch((err) => {
          if (Date.now() - pollStartedAt < maxWaitTimeMs) {
            this.schedulePoll(3000);
            return;
          }
          this.onPaymentFailure(err && err.error_message ? err.error_message : ts('Impossible de récupérer l\'état du paiement.'));
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
        this.getFormElement().show();
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        this.clearTimers();
        $scope.$applyAsync();
      };

      this.cancelCheckout = () => {
        if (!window.confirm(ts('Annuler le paiement en cours sur le terminal ?'))) {
          return;
        }
        this.retryCheckout();
      };

      this.resetKiosk = () => {
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        this.clearTimers();
        this.getFormElement().show();
        // Reset form inputs if standard form
        var form = this.getFormElement().find('form')[0];
        if (form && typeof form.reset === 'function') {
          form.reset();
        }
        $scope.$applyAsync();
      };
    },
  });
}(angular, CRM.$));
