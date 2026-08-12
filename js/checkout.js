(function (ts) {
  'use strict';

  var scripts = {};
  var instance = 0;

  function loadScript(url, isReady) {
    if (isReady()) {
      return Promise.resolve();
    }
    if (!scripts[url]) {
      scripts[url] = new Promise(function (resolve, reject) {
        var script = document.createElement('script');
        script.src = url;
        script.async = true;
        script.onload = function () {
          isReady() ? resolve() : reject(new Error('SDK unavailable'));
        };
        script.onerror = reject;
        document.head.appendChild(script);
      });
    }
    return scripts[url];
  }

  function normaliseConfig(container, config) {
    config = config || {};
    var dataset = container.dataset || {};
    return {
      checkoutId: config.checkout_id || dataset.checkoutId,
      amount: config.amount || dataset.amount,
      currency: config.currency || dataset.currency,
      locale: config.locale || dataset.locale,
      mode: config.mode || dataset.mode || 'widget',
      publicKey: config.public_key || dataset.publicKey || '',
      countryCode: config.country_code || dataset.countryCode || '',
      browserReturnUrl: config.browser_return_url || dataset.browserReturnUrl || window.location.href,
      cancelUrl: config.cancel_url || dataset.cancelUrl || '',
    };
  }

  function mount(container, suppliedConfig) {
    if (!container) {
      return Promise.reject(new Error('Missing SumUp checkout container'));
    }

    var config = normaliseConfig(container, suppliedConfig);
    var usesWidget = config.mode === 'widget' || config.mode === 'widget_wallet';
    var usesWallet = config.mode === 'wallet' || config.mode === 'widget_wallet';
    var id = ++instance;
    var message = document.createElement('p');
    message.className = 'messages status no-popup';
    message.hidden = true;

    function showMessage(text) {
      message.hidden = false;
      message.textContent = text;
    }

    function verifyOnServer() {
      window.location.assign(config.browserReturnUrl);
    }

    container.replaceChildren();
    container.appendChild(message);

    var tasks = [];
    if (usesWallet) {
      var wallet = document.createElement('div');
      wallet.id = 'sumup-wallet-' + id;
      container.insertBefore(wallet, message);
      tasks.push(loadScript(
        'https://js.sumup.com/swift-checkout/v1/sdk.js',
        function () { return Boolean(window.SumUp && window.SumUp.SwiftCheckout); }
      ).then(function () {
        var client = new window.SumUp.SwiftCheckout(config.publicKey);
        var request = client.paymentRequest({
          countryCode: config.countryCode,
          locale: config.locale,
          total: {
            label: ts('CiviCRM payment'),
            amount: {currency: config.currency, value: config.amount},
          },
        });
        var buttons = client.elements({label: 'pay'});
        buttons.onSubmit(function (event) {
          request.show(event)
            .then(function (response) {
              return client.processCheckout(config.checkoutId, response);
            })
            .then(verifyOnServer)
            .catch(function (error) {
              var errors = window.SumUp.SwiftCheckout.Errors;
              if (errors && error instanceof errors.PaymentRequestCancelledError) {
                return;
              }
              showMessage(ts('The wallet payment could not be confirmed. You can try again or cancel.'));
            });
        });
        return request.canMakePayment().then(function (available) {
          return available ? request.availablePaymentMethods() : [];
        }).then(function (methods) {
          if (methods.length) {
            buttons.mount({paymentMethods: methods, container: wallet});
          }
          else if (!usesWidget) {
            showMessage(ts('No compatible wallet is available in this browser.'));
          }
        });
      }).catch(function () {
        if (!usesWidget) {
          showMessage(ts('The wallet payment form could not be loaded.'));
        }
      }));
    }

    if (usesWidget) {
      if (usesWallet) {
        var separator = document.createElement('p');
        separator.className = 'crm-sumup-payment-separator';
        separator.textContent = ts('Or pay by card');
        container.insertBefore(separator, message);
      }
      var card = document.createElement('div');
      card.id = 'sumup-card-' + id;
      container.insertBefore(card, message);
      tasks.push(loadScript(
        'https://gateway.sumup.com/gateway/ecom/card/v2/sdk.js',
        function () { return Boolean(window.SumUpCard); }
      ).then(function () {
        window.SumUpCard.mount({
          id: card.id,
          checkoutId: config.checkoutId,
          amount: config.amount,
          currency: config.currency,
          locale: config.locale,
          onResponse: function (type) {
            if (type === 'success') {
              verifyOnServer();
            }
            else if (type === 'fail' || type === 'error') {
              showMessage(ts('The payment could not be confirmed. You can try again or cancel.'));
            }
          },
        });
      }).catch(function () {
        showMessage(ts('The card payment form could not be loaded.'));
      }));
    }

    if (config.cancelUrl) {
      var cancel = document.createElement('a');
      cancel.href = config.cancelUrl;
      cancel.textContent = ts('Cancel payment');
      var cancelParagraph = document.createElement('p');
      cancelParagraph.appendChild(cancel);
      container.appendChild(cancelParagraph);
    }

    return Promise.all(tasks);
  }

  window.CiviSumUpCheckout = {mount: mount};

  document.addEventListener('DOMContentLoaded', function () {
    var relay = document.getElementById('sumup-checkout');
    if (relay) {
      mount(relay);
    }
  });
}(CRM.ts('sumup-payment-processor')));
