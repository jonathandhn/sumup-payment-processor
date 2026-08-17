(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpHybridCheckout', {
    require: {afCheckoutBlock: '^^afCheckoutBlock'},
    templateUrl: '~/afSumUp/sumUpHybridCheckout.html',
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
      this.allowSendEmail = false;
      this.allowSendSms = false;
      this.contactEmail = '';
      this.contactPhone = '';
      this.sendLink = {
        open: false,
        channel: 'sms',
        recipient: '',
        sending: false,
        success: '',
        error: '',
      };

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
        var checkout = response && response[0] && response[0].sumup_hybrid_checkout;
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
        this.allowSendEmail = !!checkout.allow_send_email;
        this.allowSendSms = !!checkout.allow_send_sms;
        this.contactEmail = checkout.contact_email || '';
        this.contactPhone = checkout.contact_phone || '';

        var defaultChannel = this.allowSendSms ? 'sms' : 'email';
        var defaultRecipient = (defaultChannel === 'sms') ? this.contactPhone : this.contactEmail;
        this.sendLink = {
          open: false,
          channel: defaultChannel,
          recipient: defaultRecipient,
          sending: false,
          success: '',
          error: '',
        };

        if (checkout.qr_svg) {
          this.qrSvgTrusted = $sce.trustAsHtml(checkout.qr_svg);
        }

        pollStartedAt = Date.now();
        this.remainingSeconds = Math.round(terminalTimeoutMs / 1000);
        this.startCountdown();
        this.schedulePoll(1500);
        $scope.$applyAsync();
      };

      this.toggleSendLink = (open) => {
        this.sendLink.open = open;
        this.sendLink.error = '';
        this.sendLink.success = '';
        if (open && !this.sendLink.recipient) {
          this.sendLink.recipient = (this.sendLink.channel === 'sms') ? this.contactPhone : this.contactEmail;
        }
      };

      this.setSendChannel = (channel) => {
        this.sendLink.channel = channel;
        this.sendLink.recipient = (channel === 'sms') ? this.contactPhone : this.contactEmail;
        this.sendLink.error = '';
        this.sendLink.success = '';
      };

      this.sendPaymentLink = () => {
        if (!this.sendLink.recipient || this.sendLink.sending || !this.token) {
          return;
        }
        this.sendLink.sending = true;
        this.sendLink.error = '';
        this.sendLink.success = '';

        CRM.api4('SumupCheckout', 'sendPaymentLink', {
          token: this.token,
          channel: this.sendLink.channel,
          recipient: this.sendLink.recipient,
        }).then((res) => {
          var result = res[0] || {};
          this.sendLink.success = result.message || ts('Payment link sent!');
        }).catch((err) => {
          this.sendLink.error = (err && (err.error_message || err.message || (err.responseJSON && err.responseJSON.error_message)))
            ? (err.error_message || err.message || err.responseJSON.error_message)
            : ts('Failed to send payment link.');
        }).finally(() => {
          this.sendLink.sending = false;
          $scope.$applyAsync();
        });
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
            this.waiting = false;
            this.failed = true;
            this.errorMessage = ts('The payment session timed out. Please try again.');
            this.clearTimers();
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
          url: CRM.url('civicrm/checkout/continue', {session: this.token}),
          type: 'POST',
          dataType: 'json',
        }).done((res) => {
          var status = (res && res.status) ? res.status : '';
          if (status === 'success') {
            this.waiting = false;
            this.completed = true;
            this.failed = false;
            this.receiptRef = (res && res.response && res.response.receipt_ref) || this.receiptRef;
            this.clearTimers();
          } else if (status === 'failed') {
            this.waiting = false;
            this.failed = true;
            this.errorMessage = (res && res.message) || ts('The payment was cancelled or failed.');
            this.clearTimers();
          } else if (status === 'cancelled') {
            this.resetKiosk();
            return;
          } else {
            this.schedulePoll(1500);
          }
          $scope.$applyAsync();
        }).fail(() => {
          this.schedulePoll(2500);
        });
      };

      this.retryTerminal = () => {
        if (this.retryingTerminal || !this.token) {
          return;
        }

        this.retryingTerminal = true;
        this.terminalRetryError = '';
        CRM.api4('SumupTerminalCheckout', 'retry', {
          token: this.token,
        }).then((res) => {
          var result = res[0] || {};
          this.receiptRef = result.client_transaction_id || this.receiptRef;
          pollStartedAt = Date.now();
          this.terminalExpired = false;
          this.remainingSeconds = Math.round(terminalTimeoutMs / 1000);
        }).catch((err) => {
          this.terminalRetryError = err.error_message || ts('Unable to communicate with the card reader.');
        }).finally(() => {
          this.retryingTerminal = false;
          $scope.$applyAsync();
        });
      };

      this.cancelCheckout = () => {
        this.clearTimers();
        if (this.token) {
          try {
            if (navigator.sendBeacon) {
              navigator.sendBeacon(CRM.url('civicrm/checkout/cancel', {token: this.token}));
            } else {
              $.ajax({
                url: CRM.url('civicrm/checkout/cancel', {token: this.token}),
                type: 'POST',
                async: false,
              });
            }
          } catch (e) {}
        }
        $window.location.reload();
      };

      this.resetKiosk = () => {
        this.clearTimers();
        this.active = false;
        this.waiting = false;
        this.completed = false;
        this.failed = false;
        this.token = '';
        $window.location.reload();
      };

      this.retryCheckout = () => {
        this.resetKiosk();
      };
    },
  });
})(angular, CRM.$);
