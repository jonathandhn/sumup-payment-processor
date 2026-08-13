(function ($, Drupal, ts) {
  'use strict';

  function normaliseHttpUrl(value) {
    try {
      var url = new URL(value, window.location.href);
      return /^https?:$/.test(url.protocol) ? url : null;
    }
    catch (error) {
      return null;
    }
  }

  function clearUnloadWarnings() {
    window.onbeforeunload = null;
    $(window).off('beforeunload');
  }

  function getAjaxForm(ajax) {
    if (!ajax || !ajax.element) {
      return null;
    }
    if (ajax.element.form) {
      return ajax.element.form;
    }
    if (typeof ajax.element.closest === 'function') {
      return ajax.element.closest('form');
    }
    return null;
  }

  function showRedirectFallback(rawUrl, providerName, ajax) {
    var url = normaliseHttpUrl(rawUrl);
    if (!url) {
      return;
    }

    var existing = document.getElementById('sumup-secure-redirect');
    if (existing) {
      existing.remove();
    }

    var panel = document.createElement('section');
    panel.id = 'sumup-secure-redirect';
    panel.className = 'crm-block crm-sumup-panel messages status no-popup';
    panel.setAttribute('role', 'status');
    panel.setAttribute('aria-live', 'polite');

    var title = document.createElement('h2');
    title.textContent = ts('Secure redirect');
    var message = document.createElement('p');
    message.textContent = ts('You are being redirected to %1 to complete your payment securely.', {1: providerName});
    var fallback = document.createElement('p');
    var link = document.createElement('a');
    link.className = 'button crm-button btn btn-primary';
    link.href = url.href;
    link.target = '_top';
    link.rel = 'noopener';
    link.textContent = ts('Continue to secure payment');
    fallback.appendChild(link);
    var warning = document.createElement('p');
    warning.hidden = true;
    warning.textContent = ts('The automatic redirect appears to be blocked. Use the button above to continue.');

    panel.appendChild(title);
    panel.appendChild(message);
    panel.appendChild(fallback);
    panel.appendChild(warning);
    var form = getAjaxForm(ajax);
    if (form) {
      form.hidden = true;
      form.setAttribute('aria-hidden', 'true');
    }
    document.body.insertBefore(panel, document.body.firstChild);

    clearUnloadWarnings();
    window.setTimeout(function () {
      try {
        window.top.location.href = url.href;
      }
      catch (error) {
        warning.hidden = false;
      }
    }, 200);
    window.setTimeout(function () {
      warning.hidden = false;
    }, 4000);
  }

  if (Drupal && Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.sumupRedirect = function (ajax, response) {
      if (!response.url) {
        return;
      }

      showRedirectFallback(response.url, 'SumUp', ajax);
    };

    Drupal.AjaxCommands.prototype.sumupMountCheckout = function (ajax, response) {
      var checkout = response.checkout || {};
      var fallbackUrl = response.fallback_url || checkout.browser_return_url;
      if (!checkout.checkout_id || !window.CiviSumUpCheckout) {
        showRedirectFallback(fallbackUrl, 'SumUp', ajax);
        return;
      }

      var form = getAjaxForm(ajax) || document.querySelector('form.webform-submission-form');
      var existing = document.getElementById('sumup-webform-checkout');
      if (existing) {
        existing.remove();
      }

      var section = document.createElement('section');
      section.id = 'sumup-webform-checkout';
      section.className = 'crm-block crm-sumup-checkout-block';
      section.setAttribute('aria-busy', 'true');
      var title = document.createElement('h2');
      title.textContent = ts('Secure payment');
      var container = document.createElement('div');
      container.className = 'crm-sumup-embedded-checkout';
      section.appendChild(title);
      section.appendChild(container);

      if (form) {
        form.hidden = true;
        form.setAttribute('aria-hidden', 'true');
        form.insertAdjacentElement('afterend', section);
      }
      else {
        document.body.appendChild(section);
      }

      clearUnloadWarnings();
      window.CiviSumUpCheckout.mount(container, checkout).then(function () {
        section.setAttribute('aria-busy', 'false');
      }).catch(function () {
        section.remove();
        if (form) {
          form.hidden = false;
          form.removeAttribute('aria-hidden');
        }
        showRedirectFallback(fallbackUrl, 'SumUp', ajax);
      });
    };
  }
}(CRM.$, window.Drupal, CRM.ts('sumup-payment-processor')));
