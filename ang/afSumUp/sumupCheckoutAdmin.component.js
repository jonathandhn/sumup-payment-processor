(function (angular) {
  'use strict';

  angular.module('afSumUp').component('sumupCheckoutAdmin', {
    bindings: {
      node: '='
    },
    templateUrl: '~/afSumUp/sumupCheckoutAdmin.html',
    controller: function ($scope) {
      var ts = $scope.ts = CRM.ts('sumup-payment-processor');
      this.readers = [];

      this.$onInit = () => {
        CRM.api4('SumupReader', 'get', {
          where: [['pairing_status', '=', 'paired'], ['is_active', '=', true]],
          select: ['id', 'canonical_name', 'site_code']
        }).then((res) => {
          this.readers = res.map((r) => ({
            id: r.id,
            label: r.canonical_name + (r.site_code ? ' [' + r.site_code + ']' : '')
          }));
          $scope.$applyAsync();
        });
      };
    }
  });
})(angular);
