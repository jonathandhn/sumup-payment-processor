(function ($, Drupal) {
  'use strict';

  if (Drupal && Drupal.AjaxCommands) {
    Drupal.AjaxCommands.prototype.sumupRedirect = function (ajax, response) {
      if (!response.url) {
        return;
      }
      try {
        window.top.location.href = response.url;
      }
      catch (error) {
        window.location.href = response.url;
      }
    };
  }
}(CRM.$, window.Drupal));
