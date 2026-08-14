(function ($) {
  'use strict';

  var config = CRM.vars.sumupQuickForm || {};
  var processorIds = (config.processorIds || []).map(String);

  function selectedProcessorId($form) {
    var $checked = $form.find('[name="payment_processor_id"]:checked');
    var $field = $checked.length ? $checked : $form.find('[name="payment_processor_id"]').first();
    return $field.length ? String($field.val() || '') : '';
  }

  function usesSumUp($form) {
    return processorIds.indexOf(selectedProcessorId($form)) !== -1;
  }

  function displayCrmMessages(response, $response) {
    var displayed = false;
    var messages = response && response.crmMessages;

    if (messages && typeof messages === 'object') {
      Object.keys(messages).forEach(function (key) {
        var message = messages[key];
        if (!message || !message.text) {
          return;
        }
        CRM.alert(message.text, message.title || '', message.type || 'error', message.options || {});
        displayed = true;
      });
    }

    if (!displayed && $response && response.isHtmlDocument) {
      var $message = $response
        .find('#crm-notification-container .ui-notify-message, .messages--error, .messages.error, .crm-error, [role="alert"]')
        .first();
      if ($message.length) {
        CRM.alert($message.html() || $message.text(), '', 'error', {expires: 0});
        displayed = true;
      }
    }

    return displayed;
  }

  function displayFormResponse($form, response) {
    if (!response || !response.content) {
      if (displayCrmMessages(response)) {
        return;
      }
      CRM.alert(CRM.ts('sumup-payment-processor')('Unable to start the secure payment form. Please try again.'), '', 'error');
      return;
    }
    var $response = $('<div>').html(response.content);
    var displayedMessage = displayCrmMessages(response, $response);
    if (response.isHtmlDocument) {
      if (!displayedMessage) {
        CRM.alert(CRM.ts('sumup-payment-processor')('Unable to start the secure payment form. Please try again.'), '', 'error');
      }
      return;
    }
    var $replacement = $response.find('form').first();
    if (!$replacement.length) {
      if (!displayedMessage) {
        CRM.alert(CRM.ts('sumup-payment-processor')('Unable to start the secure payment form. Please try again.'), '', 'error');
      }
      return;
    }
    $form.replaceWith($replacement);
    $replacement.trigger('crmLoad', response);
  }

  function mountCheckout($form, checkout) {
    var container = document.createElement('div');
    container.className = 'crm-sumup-embedded-checkout';
    $form.hide();
    $form.after(container);
    window.CiviSumUpCheckout.mount(container, checkout).catch(function () {
      CRM.alert(CRM.ts('sumup-payment-processor')('Unable to start the secure payment form. Please try again.'), '', 'error');
    });
  }

  $(document).on('click.sumupQuickForm', 'form [type="submit"]', function () {
    $(this.form).data('sumupSubmitButton', {name: this.name, value: this.value});
  });

  function submitQuickForm(event) {
    var form = event.target;
    if (!form || form.nodeName !== 'FORM') {
      return;
    }
    var $form = $(form);
    if (!usesSumUp($form) || $form.data('sumupSubmitting')) {
      return;
    }
    if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
      event.preventDefault();
      form.reportValidity && form.reportValidity();
      return;
    }
    if (typeof $form.valid === 'function' && !$form.valid()) {
      event.preventDefault();
      return;
    }

    event.preventDefault();
    event.stopImmediatePropagation();
    $form.data('sumupSubmitting', true);
    var data = new FormData(form);
    var submitButton = event.submitter || $form.data('sumupSubmitButton');
    if (submitButton && submitButton.name) {
      data.set(submitButton.name, submitButton.value);
    }
    data.set('snippet', '6');
    data.set('sumup_quickform_embed', '1');

    fetch(form.action || window.location.href, {
      method: 'POST',
      body: data,
      credentials: 'same-origin',
      headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'},
    }).then(function (response) {
      var contentType = response.headers.get('content-type') || '';
      if (contentType.indexOf('application/json') !== -1) {
        return response.json();
      }
      return response.text().then(function (content) {
        return {content: content, isHtmlDocument: true};
      });
    }).then(function (response) {
      if (response.sumup_embedded_checkout) {
        mountCheckout($form, response.sumup_embedded_checkout);
      }
      else {
        $form.data('sumupSubmitting', false);
        displayFormResponse($form, response);
      }
    }).catch(function () {
      $form.data('sumupSubmitting', false);
      CRM.alert(CRM.ts('sumup-payment-processor')('Unable to start the secure payment form. Please try again.'), '', 'error');
    });
  }

  document.addEventListener('submit', submitQuickForm, true);
  $(document).on('submit.sumupQuickForm', 'form', submitQuickForm);
}(CRM.$));
