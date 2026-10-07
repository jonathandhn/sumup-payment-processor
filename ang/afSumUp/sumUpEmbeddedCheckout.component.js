(function (angular, $) {
  'use strict';

  angular.module('afSumUp').component('afSumUpEmbeddedCheckout', {
    require: {
      afCheckoutBlock: '^^afCheckoutBlock',
      // Optional: notify the payment orchestrator when this checkout becomes active.
      orchestrator: '?^^crmPaymentOrchestrator'
    },
    templateUrl: '~/afSumUp/sumUpEmbeddedCheckout.html',
    controller: function ($scope, $element) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var listener = (event, data) => this.onAfformSuccess(data);

      this.$onInit = () => {
        loadSdkScript('https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js');
        this.getFormElement().on('crmFormSuccess', listener);
        $scope.$watch(
          () => this.afCheckoutBlock.getCheckoutOptionKey(),
          (checkoutOption) => {
            if (checkoutOption) {
              this.afCheckoutBlock.checkout_params.sumup_edit_return_url = window.location.href;
            }
          }
        );
      };
      this.$onDestroy = () => this.getFormElement().off('crmFormSuccess', listener);
      this.$postLink = () => {
        // ^^require doesn't cross transclusion — find orchestrator via querySelector.
        var formEl = $element[0].closest('af-form');
        if (formEl) {
          var orchEl = formEl.querySelector('crm-payment-orchestrator');
          if (orchEl) {
            this._orch = angular.element(orchEl).controller('crmPaymentOrchestrator');
          }
        }
      };
      this.getFormElement = () => $element.closest('af-form');

      function loadSdkScript(url) {
        return new Promise(function (resolve, reject) {
          if (document.querySelector('script[src="' + url + '"]')) {
            resolve();
            return;
          }
          // Temporarily disable AMD so the UMD SumUp SDK does not call
          // define() and conflict with CiviCRM's RequireJS (FormBuilder).
          var savedDefine = window.define;
          window.define = undefined;
          var script = document.createElement('script');
          script.src = url;
          script.async = true;
          script.onload = function () { window.define = savedDefine; resolve(); };
          script.onerror = function () { window.define = savedDefine; reject(new Error('Failed to load ' + url)); };
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
      this.donorSummary = '';
      this.cancelling = false;

      this.isDirect = () => {
        return !!(this._orch && this._orch.directMount);
      };

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
        this.donorSummary = nameParts.join(' · ') || email || '';

        this.active = true;
        this.completed = false;
        this.amount = checkout.amount || '';
        this.currency = checkout.currency || 'EUR';
        this.checkout = checkout;
        this.error = '';
        var orch = this._orch || this.orchestrator;
        if (orch) { orch.setActive(true); }

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

      this.unlockForm = () => {
        var form = this.getFormElement();
        form.find('button[type="submit"]').show();
        this.active = false;
        this.completed = false;
        this.amount = '';
        this.currency = 'EUR';
        this.checkout = null;
        this.donorSummary = '';
        this.cancelling = false;
        this.error = '';
        var orch = this._orch || this.orchestrator;
        if (orch && typeof orch.cancelActive === 'function') {
          // Reset both provider activity and the shared single-page layout.
          // setActive(false) alone leaves the orchestrator in its submitted
          // state, which keeps the Afform fields locked and its submit button hidden.
          orch.cancelActive();
        }
        else if (orch) {
          orch.setActive(false);
        }
        var container = $element[0].querySelector('#sumup-afform-mount-target');
        if (container) {
          container.innerHTML = '';
        }
        $scope.$applyAsync();
      };

      this.cancelAndUnlock = () => {
        // Cancel through the signed CiviCRM endpoint without navigating away.
        // This keeps the live Afform models intact while the server verifies
        // SumUp and cancels the existing pending Contribution.
        if (this.checkout && this.checkout.cancel_url) {
          this.cancelling = true;
          this.error = '';
          window.fetch(this.checkout.cancel_url, {
            credentials: 'same-origin',
            redirect: 'follow'
          }).then((response) => {
            if (!response.ok) {
              throw new Error('Cancellation returned HTTP ' + response.status);
            }
            this.unlockForm();
          }).catch((err) => {
            console.error('SumUp Afform Cancellation Failure:', err);
            this.cancelling = false;
            this.error = ts('Unable to cancel the payment. Please try again.');
            $scope.$applyAsync();
          });
          return;
        }
        this.unlockForm();
      };
    },
  });
}(angular, CRM.$));
