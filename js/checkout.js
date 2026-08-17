(function (ts) {
  'use strict';

  var instance = 0;

  function whenReady(getter, maxWait) {
    if (getter()) {
      return Promise.resolve();
    }
    return new Promise(function (resolve, reject) {
      var interval = 50;
      var elapsed = 0;
      var max = maxWait || 6000;
      var timer = setInterval(function () {
        if (getter()) {
          clearInterval(timer);
          resolve();
        } else {
          elapsed += interval;
          if (elapsed >= max) {
            clearInterval(timer);
            reject(new Error('SDK timeout'));
          }
        }
      }, interval);
    });
  }

  function normaliseConfig(container, config) {
    config = config || {};
    var dataset = container.dataset || {};
    var savedConfig = CRM.vars.sumupSavedPayment || {};
    if (savedConfig.checkout_id !== (config.checkout_id || dataset.checkoutId)) {
      savedConfig = {};
    }
    return {
      checkoutId: config.checkout_id || dataset.checkoutId,
      amount: config.amount || dataset.amount,
      currency: config.currency || dataset.currency,
      locale: config.locale || dataset.locale,
      mode: config.mode || dataset.mode || 'widget',
      publicKey: config.public_key || dataset.publicKey || '',
      businessName: config.business_name || dataset.businessName || '',
      countryCode: config.country_code || dataset.countryCode || '',
      walletsAllowed: config.wallets_allowed === true
        || config.wallets_allowed === 1
        || dataset.walletsAllowed === '1',
      browserReturnUrl: config.browser_return_url || dataset.browserReturnUrl || window.location.href,
      cancelUrl: config.cancel_url || dataset.cancelUrl || '',
      onSuccess: typeof config.onSuccess === 'function' ? config.onSuccess : null,
      savedPaymentMethods: config.saved_payment_methods || savedConfig.saved_payment_methods || [],
      savedPaymentAction: config.saved_payment_action || savedConfig.saved_payment_action || null,
      acceptedCards: config.accepted_cards
        || (dataset.acceptedCards ? JSON.parse(dataset.acceptedCards) : ['Visa', 'MasterCard']),
    };
  }

  function mount(container, suppliedConfig) {
    if (!container) {
      return Promise.reject(new Error('Missing SumUp checkout container'));
    }

    var config = normaliseConfig(container, suppliedConfig);
    var usesWidget = config.mode === 'widget' || config.mode === 'widget_wallet';
    var usesWallet = Boolean(config.walletsAllowed)
      && Boolean(config.publicKey && config.publicKey.trim() !== '')
      && (config.mode === 'wallet' || config.mode === 'widget_wallet');
    var id = ++instance;
    var message = document.createElement('p');
    message.className = 'messages status no-popup';
    message.hidden = true;

    function showMessage(text) {
      message.hidden = false;
      message.textContent = text;
    }

    function verifyOnServer() {
      if (config.onSuccess) {
        config.onSuccess();
        return;
      }
      window.location.assign(config.browserReturnUrl);
    }

    function followNextStep(nextStep) {
      if (!nextStep || !nextStep.url) {
        throw new Error('Missing SumUp authentication step');
      }
      var method = String(nextStep.method || 'GET').toUpperCase();
      var payload = nextStep.payload || {};
      if (method === 'GET') {
        var url = new URL(nextStep.url);
        Object.keys(payload).forEach(function (name) {
          if (payload[name] !== null) {
            url.searchParams.set(name, String(payload[name]));
          }
        });
        window.location.assign(url.toString());
        return;
      }
      if (method !== 'POST') {
        throw new Error('Unsupported SumUp authentication method');
      }
      var form = document.createElement('form');
      form.method = 'POST';
      form.action = nextStep.url;
      Object.keys(payload).forEach(function (name) {
        if (payload[name] === null) {
          return;
        }
        var input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(payload[name]);
        form.appendChild(input);
      });
      document.body.appendChild(form);
      form.submit();
    }

    function payWithSavedCard(paymentTokenId, button) {
      if (!config.savedPaymentAction) {
        return;
      }
      button.disabled = true;
      showMessage(ts('Confirming payment with your saved card…'));
      CRM.api4('SumupPaymentMethod', 'payContribution', Object.assign(
        {},
        config.savedPaymentAction,
        {paymentTokenId: paymentTokenId}
      )).then(function (results) {
        var result = results[0] || {};
        if (result.status === 'PAID') {
          verifyOnServer();
          return;
        }
        if (result.status === 'CUSTOMER_ACTION_REQUIRED') {
          followNextStep(result.next_step);
          return;
        }
        showMessage(ts('The saved-card payment is still pending. Its status will be checked again.'));
        window.setTimeout(verifyOnServer, 1500);
      }).catch(function () {
        button.disabled = false;
        showMessage(ts('This saved card could not be used. You can choose another card below.'));
      });
    }

    container.replaceChildren();
    container.appendChild(message);

    if (config.businessName) {
      var merchantTrust = document.createElement('p');
      merchantTrust.className = 'crm-sumup-merchant-trust';
      merchantTrust.appendChild(document.createTextNode(ts('Secure payment to') + ' '));
      var merchantName = document.createElement('strong');
      merchantName.textContent = config.businessName;
      merchantTrust.appendChild(merchantName);
      container.insertBefore(merchantTrust, message);
    }

    var tasks = [];
    var walletCardSeparator = null;

    if (usesWallet) {
      var wallet = document.createElement('div');
      wallet.id = 'sumup-wallet-' + id;
      wallet.hidden = true;
      wallet.style.display = 'none';
      container.insertBefore(wallet, message);
      var removeWallet = function () {
        if (wallet.isConnected) {
          wallet.remove();
        }
        if (walletCardSeparator) {
          walletCardSeparator.remove();
          walletCardSeparator = null;
        }
      };
      var hasError = function () {
        return Boolean(wallet.querySelector(
          '[data-testid*="error"], [class*="error"], [data-testid="timeout-session-error-message"]'
        ));
      };

      var hasVisibleWalletControl = function () {
        if (hasError()) {
          return false;
        }
        return Array.prototype.some.call(wallet.querySelectorAll(
          'button, iframe, [role="button"], [data-testid$="-pay-button"]'
        ), function (control) {
          if (control.closest('[data-testid*="error"], [class*="error"]')) {
            return false;
          }
          var style = window.getComputedStyle(control);
          var rect = control.getBoundingClientRect();
          return style.display !== 'none'
            && style.visibility !== 'hidden'
            && style.opacity !== '0'
            && rect.width > 20
            && rect.height > 20;
        });
      };

      if (window.MutationObserver) {
        var observer = new window.MutationObserver(function () {
          if (hasError()) {
            observer.disconnect();
            removeWallet();
          }
        });
        observer.observe(wallet, {childList: true, subtree: true});
      }
      tasks.push(whenReady(function () {
        return Boolean(window.SumUp && window.SumUp.SwiftCheckout);
      }).then(function () {
        var client = new window.SumUp.SwiftCheckout(config.publicKey);
        var request = client.paymentRequest({
          countryCode: config.countryCode,
          locale: config.locale,
          total: {
            label: config.businessName || ts('CiviCRM payment'),
            amount: {currency: config.currency, value: config.amount},
          },
        });
        return request.canMakePayment().then(function (available) {
          return available ? request.availablePaymentMethods() : [];
        }).then(function (methods) {
          if (methods.length) {
            var walletBusy = false;
            var buttons = client.elements({label: 'pay'});
            buttons.onSubmit(function (event) {
              if (walletBusy) {
                return;
              }
              walletBusy = true;
              wallet.setAttribute('aria-busy', 'true');
              wallet.style.pointerEvents = 'none';
              request.show(event)
                .then(function (response) {
                  return client.processCheckout(config.checkoutId, response);
                })
                .then(verifyOnServer)
                .catch(function (error) {
                  var errors = window.SumUp.SwiftCheckout.Errors;
                  if (!(errors && error instanceof errors.PaymentRequestCancelledError)) {
                    showMessage(ts('The wallet payment could not be confirmed. You can try again or cancel.'));
                  }
                })
                .finally(function () {
                  walletBusy = false;
                  wallet.removeAttribute('aria-busy');
                  wallet.style.removeProperty('pointer-events');
                });
            });
            wallet.hidden = false;
            wallet.style.display = 'flex';
            wallet.style.maxHeight = '0';
            wallet.style.overflow = 'hidden';
            wallet.style.visibility = 'hidden';
            buttons.mount({paymentMethods: methods, container: wallet});
            return new Promise(function (resolve) {
              var checksRemaining = 16;
              var visibleChecks = 0;
              var revealWhenRendered = function () {
                visibleChecks = hasVisibleWalletControl() ? visibleChecks + 1 : 0;
                if (visibleChecks >= 2) {
                  wallet.style.removeProperty('max-height');
                  wallet.style.removeProperty('overflow');
                  wallet.style.removeProperty('visibility');
                  if (walletCardSeparator) {
                    walletCardSeparator.hidden = false;
                    walletCardSeparator.style.removeProperty('display');
                  }
                  resolve();
                  return;
                }
                checksRemaining -= 1;
                if (checksRemaining > 0) {
                  window.setTimeout(revealWhenRendered, 250);
                  return;
                }
                removeWallet();
                if (!usesWidget) {
                  showMessage(ts('No compatible wallet is available in this browser.'));
                }
                resolve();
              };
              window.setTimeout(revealWhenRendered, 0);
            });
          }
          else {
            removeWallet();
            if (!usesWidget) {
              showMessage(ts('No compatible wallet is available in this browser.'));
            }
          }
        });
      }).catch(function () {
        removeWallet();
        if (!usesWidget) {
          showMessage(ts('The wallet payment form could not be loaded.'));
        }
      }));
    }

    if (usesWidget) {
      if (usesWallet) {
        walletCardSeparator = document.createElement('p');
        walletCardSeparator.className = 'crm-sumup-payment-separator';
        walletCardSeparator.textContent = ts('Or pay by card');
        walletCardSeparator.hidden = true;
        walletCardSeparator.style.display = 'none';
        container.insertBefore(walletCardSeparator, message);
      }

      var card = document.createElement('div');
      card.id = 'sumup-card-' + id;

      if (config.savedPaymentMethods.length) {
        var selector = document.createElement('div');
        selector.className = 'crm-sumup-method-selector';

        var radioGroupName = 'sumup_method_choice_' + id;
        var choiceContainers = [];
        var drawerContainers = [];

        var selectChoice = function (index) {
          choiceContainers.forEach(function (c, i) {
            var r = c.querySelector('input[type="radio"]');
            if (i === index) {
              c.classList.add('crm-sumup-choice--selected');
              if (r) {
                r.checked = true;
              }
              drawerContainers[i].style.display = 'block';
              if (i === newCardIndex) {
                mountCardWidget();
              }
            } else {
              c.classList.remove('crm-sumup-choice--selected');
              if (r) {
                r.checked = false;
              }
              drawerContainers[i].style.display = 'none';
            }
          });
        };

        // 1. Saved card options (first one selected by default)
        config.savedPaymentMethods.forEach(function (method, idx) {
          var choice = document.createElement('div');
          choice.className = 'crm-sumup-choice crm-sumup-choice--saved'
            + (idx === 0 ? ' crm-sumup-choice--selected' : '');

          var header = document.createElement('label');
          header.className = 'crm-sumup-choice__header';

          var radio = document.createElement('input');
          radio.type = 'radio';
          radio.name = radioGroupName;
          radio.value = 'saved_' + method.payment_token_id;
          radio.checked = (idx === 0);
          radio.addEventListener('change', function () {
            selectChoice(idx);
          });
          header.appendChild(radio);

          var savedCardNorm = 'generic';
          var textLower = String(method.masked_account_number || '').toLowerCase();
          if (textLower.indexOf('visa') !== -1) {
            savedCardNorm = 'visa';
          } else if (textLower.indexOf('master') !== -1) {
            savedCardNorm = 'mastercard';
          } else if (textLower.indexOf('amex') !== -1 || textLower.indexOf('american') !== -1) {
            savedCardNorm = 'amex';
          } else if (textLower.indexOf('cb') !== -1 || textLower.indexOf('carte') !== -1) {
            savedCardNorm = 'cb';
          }

          var savedIcon = document.createElement('span');
          savedIcon.className = 'crm-sumup-card-icon crm-sumup-card-icon--' + savedCardNorm;
          header.appendChild(savedIcon);

          var labelText = document.createElement('span');
          labelText.className = 'crm-sumup-choice__title';
          labelText.textContent = method.masked_account_number || ts('Saved card');
          header.appendChild(labelText);
          choice.appendChild(header);

          var drawer = document.createElement('div');
          drawer.className = 'crm-sumup-choice__drawer';
          drawer.style.display = (idx === 0) ? 'block' : 'none';

          var payBtn = document.createElement('button');
          payBtn.type = 'button';
          payBtn.className = 'button crm-button btn btn-primary crm-sumup-btn-pay';
          payBtn.textContent = ts('Pay %1 %2 with this card', {1: config.amount, 2: config.currency});
          payBtn.addEventListener('click', function () {
            payWithSavedCard(method.payment_token_id, payBtn);
          });
          drawer.appendChild(payBtn);
          choice.appendChild(drawer);

          selector.appendChild(choice);
          choiceContainers.push(choice);
          drawerContainers.push(drawer);
        });

        // 2. "Use another card" option (collapsed by default)
        var newCardIndex = config.savedPaymentMethods.length;
        var newChoice = document.createElement('div');
        newChoice.className = 'crm-sumup-choice crm-sumup-choice--new';

        var newHeader = document.createElement('label');
        newHeader.className = 'crm-sumup-choice__header';

        var newRadio = document.createElement('input');
        newRadio.type = 'radio';
        newRadio.name = radioGroupName;
        newRadio.value = 'new_card';
        newRadio.checked = false;
        newRadio.addEventListener('change', function () {
          selectChoice(newCardIndex);
        });
        newHeader.appendChild(newRadio);

        var newLabelText = document.createElement('span');
        newLabelText.className = 'crm-sumup-choice__title';
        newLabelText.textContent = ts('Pay with another credit / debit card');
        newHeader.appendChild(newLabelText);

        var cardSchemes = document.createElement('span');
        cardSchemes.className = 'crm-sumup-choice__schemes';
        (config.acceptedCards || []).forEach(function (cardName) {
          var norm = String(cardName).toLowerCase().replace(/[^a-z0-9]/g, '');
          var icon = document.createElement('span');
          icon.className = 'crm-sumup-card-icon crm-sumup-card-icon--' + norm;
          icon.title = cardName;
          cardSchemes.appendChild(icon);
        });
        newHeader.appendChild(cardSchemes);

        newChoice.appendChild(newHeader);

        var newDrawer = document.createElement('div');
        newDrawer.className = 'crm-sumup-choice__drawer';
        newDrawer.style.display = 'none';
        newDrawer.appendChild(card);
        newChoice.appendChild(newDrawer);

        selector.appendChild(newChoice);
        choiceContainers.push(newChoice);
        drawerContainers.push(newDrawer);

        container.insertBefore(selector, message);
      } else {
        container.insertBefore(card, message);
      }

      var cardMounted = false;
      var mountCardWidget = function () {
        if (cardMounted) {
          return Promise.resolve();
        }
        cardMounted = true;
        return whenReady(function () {
          return Boolean(window.SumUpCard);
        }).then(function () {
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
        });
      };

      if (!hasSavedCards) {
        tasks.push(mountCardWidget());
      }
    }

    if (config.cancelUrl) {
      var cancel = document.createElement('a');
      cancel.href = config.cancelUrl;
      cancel.textContent = ts('Cancel payment');
      var cancelParagraph = document.createElement('p');
      cancelParagraph.className = 'crm-sumup-checkout__cancel';
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
}(function (str, params) {
  return typeof CRM !== 'undefined' && typeof CRM.ts === 'function'
    ? CRM.ts('sumup-payment-processor')(str, params)
    : str;
}));
