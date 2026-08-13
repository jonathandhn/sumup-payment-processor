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
              method.start_date_display = method.start_date ? CRM.utils.formatDate(method.start_date) : '';
              method.end_date_display = method.end_date ? CRM.utils.formatDate(method.end_date) : '';
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

      this.sendManagementLink = (method, linkType) => {
        var label = linkType === 'card' ? ts('card-change') : ts('plan-change');
        if (!window.confirm(ts('Send the %1 link to this contact?', {1: label}))) {
          return;
        }
        method.sending_link = linkType;
        method.send_result = '';
        CRM.api4('SumupPaymentMethod', 'sendManagementLink', {
          contactId: Number(this.contactId),
          recurId: method.recur_id,
          linkType: linkType,
        }).then((response) => {
          method.send_result = ts('The link was sent to %1.', {1: response[0].recipient});
        }).catch((failure) => {
          method.send_result = failure.error_message || ts('Unable to send the management link.');
        }).finally(() => {
          method.sending_link = '';
          $scope.$applyAsync();
        });
      };
    },
  });
}(angular));
