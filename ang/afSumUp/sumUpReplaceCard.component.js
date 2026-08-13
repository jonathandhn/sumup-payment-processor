(function (angular) {
  'use strict';

  angular.module('afSumUp').component('afSumUpReplaceCard', {
    templateUrl: '~/afSumUp/sumUpReplaceCard.html',
    controller: function ($scope, $element, $timeout) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      var query = new URL(window.location.href).searchParams;
      var recurId = Number(query.get('recur_id'));
      var contactId = Number(query.get('cid') || query.get('cid1'));
      var checksum = query.get('cs') || '';
      var access = contactId > 0 && checksum ? {contactId: contactId, checksum: checksum} : {};
      var publicAccess = contactId > 0 && checksum ? {cid: contactId, cs: checksum} : {};
      var attempts = 0;

      this.paymentMethodsUrl = CRM.url('civicrm/sumup/payment-methods', publicAccess);

      this.$onInit = () => {
        if (!Number.isInteger(recurId) || recurId <= 0) {
          this.error = ts('The recurring payment link is invalid.');
          return;
        }
        this.loading = true;
        CRM.api4('SumupPaymentMethod', 'startReplacement', Object.assign({recurId: recurId}, access))
          .then((response) => {
            var checkout = response && response[0];
            if (!checkout) {
              throw new Error('Missing replacement checkout');
            }
            if (checkout.status === 'RESOLVED') {
              this.complete = true;
              return;
            }
            this.checkoutId = checkout.checkout_id;
            if (!window.CiviSumUpCheckout) {
              throw new Error('SumUp checkout bridge unavailable');
            }
            return window.CiviSumUpCheckout.mount(
              $element[0].querySelector('.crm-sumup-replacement-widget'),
              Object.assign({}, checkout, {onSuccess: this.continueReplacement})
            );
          })
          .catch(() => {
            this.error = ts('Unable to prepare the secure card replacement form.');
          })
          .finally(() => {
            this.loading = false;
            $scope.$applyAsync();
          });
      };

      this.continueReplacement = () => {
        this.confirming = true;
        CRM.api4('SumupPaymentMethod', 'continueReplacement', Object.assign({
          recurId: recurId,
          checkoutId: this.checkoutId,
        }, access)).then((response) => {
          var result = response && response[0];
          if (result && result.status === 'RESOLVED') {
            this.complete = true;
            this.confirming = false;
            $scope.$applyAsync();
            return;
          }
          if (result && result.status === 'PENDING' && attempts < 4) {
            attempts += 1;
            $timeout(this.continueReplacement, 2000);
            return;
          }
          this.confirming = false;
          this.error = ts('The new card could not be confirmed. You can try again.');
          $scope.$applyAsync();
        }).catch(() => {
          this.confirming = false;
          this.error = ts('The new card could not be confirmed. You can try again.');
          $scope.$applyAsync();
        });
      };
    },
  });
}(angular));
