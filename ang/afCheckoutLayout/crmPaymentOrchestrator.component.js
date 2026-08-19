(function (angular, CRM) {
  'use strict';

  // crm-payment-orchestrator — PSP-agnostic payment method orchestrator.
  //
  // Configure via the `options` attribute: a comma-separated list of
  // checkout_option keys (from CRM.afCheckout.checkoutOptions).
  //
  // Behaviour:
  //   - Before submit : right column shows only crm-checkout-summary (nothing else).
  //   - After submit  : payment content revealed.
  //                     - 1 option  → direct widget, no selector tabs.
  //                     - N options → method tabs + active widget.
  //
  // SDK preloading: all embedded-processor SDKs in the options list are
  // preloaded in parallel at $onInit so the widget mounts instantly after submit.
  //
  // Usage:
  //   <crm-payment-orchestrator entity-name="Contribution1"
  //     options="sumup_embedded_checkout_SumUP,pay_later">
  //     <fieldset af-fieldset="Contribution1" class="crm-sumup-payment-fieldset">
  //       <af-field name="checkout_params" defn="{label: false}" />
  //     </fieldset>
  //   </crm-payment-orchestrator>

  // ── Metadata for known checkout option prefixes ──────────────────────────

  var OPTION_META = [
    {
      prefix: 'sumup_embedded_checkout',
      label: 'Carte bancaire',
      icon: 'fa-credit-card',
      sdkUrl: 'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js'
    },
    { prefix: 'sumup_solo_checkout',       label: 'Terminal',          icon: 'fa-mobile' },
    { prefix: 'sumup_qr_checkout',         label: 'QR Code',           icon: 'fa-qrcode' },
    { prefix: 'sumup_hybrid_checkout',     label: 'Terminal / QR',     icon: 'fa-exchange' },
    { prefix: 'stancer_embedded_checkout', label: 'Carte (Stancer)',   icon: 'fa-credit-card' },
    { prefix: 'stancer_hosted_checkout',   label: 'Stancer',           icon: 'fa-external-link' },
    { prefix: 'helloasso_hosted_checkout', label: 'HelloAsso',         icon: 'fa-heart' },
    { prefix: 'pay_later',                 label: 'Virement bancaire', icon: 'fa-university' }
  ];

  function metaForKey(key) {
    for (var i = 0; i < OPTION_META.length; i++) {
      if (key.indexOf(OPTION_META[i].prefix) === 0) { return OPTION_META[i]; }
    }
    return { label: key, icon: 'fa-circle-o' };
  }

  // AMD-safe SDK preload (same guard as sumUpEmbeddedCheckout.component.js).
  function preloadSdk(url) {
    if (!url || document.querySelector('script[src="' + url + '"]')) { return; }
    var savedDefine = window.define;
    window.define = undefined;
    var script = document.createElement('script');
    script.src = url;
    script.async = true;
    script.onload  = function () { window.define = savedDefine; };
    script.onerror = function () { window.define = savedDefine; };
    document.head.appendChild(script);
  }

  // ── Component ─────────────────────────────────────────────────────────────

  angular.module('afCheckoutLayout').component('crmPaymentOrchestrator', {
    bindings: {
      entityName: '@?',
      // Comma-separated checkout_option keys to offer as payment methods.
      // Example: "sumup_embedded_checkout_SumUP,pay_later"
      options: '@?'
    },
    transclude: true,
    template:
      '<div class="crm-payment-orchestrator">' +

        // ── Multiple methods: boudins always visible (pre-submit selection) ──
        '<div class="crm-payment-boudins" ng-if="$ctrl.methods.length > 1">' +
          '<div class="crm-payment-boudin"' +
              ' ng-repeat="m in $ctrl.methods"' +
              ' ng-class="{\'crm-payment-boudin--active\': $ctrl.activeMethod === m.key}">' +

            // Boudin header — always clickable for method selection.
            '<div class="crm-payment-boudin__header" ng-click="$ctrl.switchMethod(m)">' +
              '<span class="crm-payment-boudin__radio">' +
                '<i class="crm-i" aria-hidden="true"' +
                   ' ng-class="$ctrl.activeMethod === m.key ? \'fa-dot-circle-o\' : \'fa-circle-o\'"></i>' +
              '</span>' +
              '<i class="crm-i {{m.icon}}" aria-hidden="true"></i>' +
              '<span class="crm-payment-boudin__label">{{m.label}}</span>' +
            '</div>' +

            // Boudin content — payment widget, visible only after submit and when active.
            '<div class="crm-payment-boudin__content"' +
                ' ng-if="$ctrl.submitted && $ctrl.activeMethod === m.key && $last">' +
              '<ng-transclude></ng-transclude>' +
            '</div>' +

          '</div>' +
        '</div>' +

        // ── Single method: widget shown directly after submit (no boudins) ──
        '<div class="crm-payment-orchestrator__content"' +
            ' ng-show="$ctrl.submitted"' +
            ' ng-if="$ctrl.methods.length <= 1">' +
          '<ng-transclude></ng-transclude>' +
        '</div>' +

      '</div>',

    controller: function ($scope, $element) {
      var ctrl = this;

      ctrl.$onInit = function () {
        ctrl.methods      = [];
        ctrl.activeMethod = null;
        ctrl.submitted    = false;
        ctrl.active       = false; // compatibility: crm-checkout-summary may read this

        if (!ctrl.entityName) {
          ctrl.entityName = resolveContributionEntity();
        }

        if (ctrl.options) {
          var seen = {};
          var keys = ctrl.options.split(',')
            .map(function (k) { return k.trim(); })
            .filter(function (k) { return k && !seen[k] && (seen[k] = true); });

          // Build method list.
          ctrl.methods = keys.map(function (key) {
            var meta = metaForKey(key);
            return { key: key, label: meta.label, icon: meta.icon };
          });

          // Preload all embedded-processor SDKs in parallel.
          keys.forEach(function (key) {
            var sdkUrl = metaForKey(key).sdkUrl;
            if (sdkUrl) { preloadSdk(sdkUrl); }
          });

          // Auto-select first method and write checkout_option into entity data.
          if (ctrl.methods.length) {
            ctrl.activeMethod = ctrl.methods[0].key;
            applyCheckoutOption(ctrl.methods[0].key);
          }
        }
      };

      ctrl.$postLink = function () {
        // Listen for successful afform submission → reveal payment content.
        // afform fires crmFormSuccess via jQuery trigger(), NOT a native DOM
        // event, so we must use jQuery .on() — addEventListener() won't fire.
        var $formEl = CRM.$(  $element[0].closest('af-form')  );
        if (!$formEl.length) { return; }

        var onSuccess = function () {
          $scope.$applyAsync(function () { ctrl.submitted = true; });
        };
        $formEl.on('crmFormSuccess', onSuccess);
        $scope.$on('$destroy', function () {
          $formEl.off('crmFormSuccess', onSuccess);
        });
      };

      // ── Public API ─────────────────────────────────────────────────────

      // Switch to a different payment method tab.
      ctrl.switchMethod = function (method) {
        ctrl.activeMethod = method.key;
        applyCheckoutOption(method.key);
      };

      // Backward-compat: child crm-payment-method components can self-register.
      ctrl.register = function (method) {
        var exists = ctrl.methods.some(function (m) { return m.key === method.key; });
        if (!exists) { ctrl.methods.push(method); }
        if (!ctrl.activeMethod) {
          ctrl.activeMethod = method.key;
          applyCheckoutOption(method.key);
        }
      };

      ctrl.unregister = function (key) {
        ctrl.methods = ctrl.methods.filter(function (m) { return m.key !== key; });
        if (ctrl.activeMethod === key && ctrl.methods.length) {
          ctrl.switchMethod(ctrl.methods[0]);
        }
      };

      // Called by a payment widget when checkout starts (val=true) or is cancelled (val=false).
      ctrl.setActive = function (val) {
        $scope.$applyAsync(function () { ctrl.active = !!val; });
      };

      // True when the key belongs to an embedded checkout that renders its own
      // tab strip inside its accordion (SumUp, Stancer embedded).
      ctrl.isEmbedded = function (key) {
        if (!key) { return false; }
        return key.indexOf('sumup_embedded_checkout') === 0 ||
               key.indexOf('stancer_embedded_checkout') === 0;
      };


      // Reset to pre-submit state (called by crm-checkout-summary "Modifier" button).
      ctrl.cancelActive = function () {
        $scope.$applyAsync(function () {
          ctrl.submitted = false;
          ctrl.active    = false;
        });

        // Also cancel any mounted payment widget.
        var formEl = $element[0].closest('af-form');
        if (!formEl) { return; }
        var checkoutEl = formEl.querySelector('af-sum-up-embedded-checkout');
        if (checkoutEl) {
          var checkoutCtrl = angular.element(checkoutEl).controller('afSumUpEmbeddedCheckout');
          if (checkoutCtrl && checkoutCtrl.active) { checkoutCtrl.cancelAndUnlock(); }
        }
      };

      // ── Private ────────────────────────────────────────────────────────

      // Write checkout_option into the live entity data so afform submit uses it.
      function applyCheckoutOption(key) {
        if (!key || !ctrl.entityName) { return; }
        var afForm = getAfForm();
        if (!afForm) { return; }
        var data = afForm.getData(ctrl.entityName);
        if (data && data[0] && data[0].fields) {
          data[0].fields.checkout_option = key;
        }
      }

      function getAfForm() {
        var formEl = $element[0].closest('af-form');
        return formEl ? angular.element(formEl).controller('afForm') : null;
      }

      function resolveContributionEntity() {
        var formEl = $element[0].closest('af-form');
        if (!formEl) { return 'Contribution1'; }
        var entity = formEl.querySelector('af-entity[type="Contribution"]');
        return entity ? entity.getAttribute('name') : 'Contribution1';
      }
    }
  });

})(angular, CRM);
