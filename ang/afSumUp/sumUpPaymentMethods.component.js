(function (angular) {
  'use strict';

  angular.module('afSumUp').component('afSumUpPaymentMethods', {
    templateUrl: '~/afSumUp/sumUpPaymentMethods.html',
    bindings: {contactId: '<?'},
    controller: function ($scope) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');

      this.$onInit = () => {
        var query = new URL(window.location.href).searchParams;
        var contactId = Number(this.contactId || query.get('cid') || query.get('cid1'));
        var checksum = query.get('cs') || '';
        var access = contactId > 0 ? {contactId: contactId} : {};
        if (checksum) {
          access.checksum = checksum;
        }
        this.loading = true;
        Promise.all([
          CRM.api4('SumupPaymentMethod', 'listCards', access),
          CRM.api4('SumupPaymentMethod', 'get', access),
        ]).then((responses) => {
            this.cards = responses[0];
            var methods = responses[1];
            methods.forEach((method) => {
              method.next_payment_display = CRM.utils.formatDate(method.next_sched_contribution_date);
            });
            this.methods = methods;
          })
          .catch(() => {
            this.error = ts('Unable to retrieve the SumUp payment methods.');
          })
          .finally(() => {
            this.loading = false;
            $scope.$applyAsync();
          });
      };
    },
  });
}(angular));
