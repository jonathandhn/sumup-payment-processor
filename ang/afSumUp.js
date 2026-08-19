(function (angular) {
  'use strict';
  // afCheckoutLayout is bundled in the same JS payload (ang/afCheckoutLayout.js
  // loads before this file). We declare it as an Angular dependency explicitly
  // since it is NOT a CiviCRM module and therefore not in CRM.angRequires().
  var requires = CRM.angRequires('afSumUp').concat(['afCheckoutLayout']);
  angular.module('afSumUp', requires);
}(angular));
