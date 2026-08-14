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
        this.access = contactId > 0 ? {contactId: contactId} : {};
        if (checksum) {
          this.access.checksum = checksum;
        }
        this.load();
      };

      this.load = () => {
        this.loading = true;
        this.error = '';
        Promise.all([
          CRM.api4('SumupPaymentMethod', 'listCards', this.access),
          CRM.api4('SumupPaymentMethod', 'get', this.access),
        ]).then((responses) => {
            this.cards = this.groupCards(responses[0]);
            var methods = responses[1];
            methods.forEach((method) => {
              method.next_payment_display = method.next_sched_contribution_date
                ? CRM.utils.formatDate(method.next_sched_contribution_date)
                : '';
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

      this.groupCards = (cards) => {
        var groups = new Map();
        cards.forEach((card) => {
          var key = [
            card.payment_processor_id,
            card.is_test ? 'test' : 'live',
            card.masked_account_number,
          ].join('|');
          if (!groups.has(key)) {
            groups.set(key, {
              masked_account_number: card.masked_account_number,
              payment_processor_title: card.payment_processor_title,
              is_test: card.is_test,
              authorisations: [],
              recurring_payment_count: 0,
            });
          }
          var group = groups.get(key);
          group.authorisations.push(card);
          group.recurring_payment_count += card.recurring_payment_count;
        });
        return Array.from(groups.values());
      };

      this.deactivateCard = (authorisation) => {
        if (!authorisation.can_deactivate || authorisation.deactivating) {
          return;
        }
        if (!window.confirm(ts(
          'Remove this saved card authorisation? It will no longer be available for future SumUp payments.'
        ))) {
          return;
        }
        authorisation.deactivating = true;
        var request = Object.assign({}, this.access, {
          paymentTokenId: authorisation.payment_token_id,
        });
        CRM.api4('SumupPaymentMethod', 'deactivateCard', request)
          .then(() => this.load())
          .catch((failure) => {
            authorisation.deactivate_error = failure.error_message || ts('Unable to remove the saved card.');
          })
          .finally(() => {
            authorisation.deactivating = false;
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
