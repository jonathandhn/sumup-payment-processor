<div class="crm-block crm-form-block crm-sumup-checkout-block">
  <header class="crm-sumup-checkout-header">
    <p class="crm-sumup-eyebrow">SumUp</p>
    <h2>{ts domain="sumup-payment-processor"}Secure payment{/ts}</h2>
  </header>
  {if $sumupCheckoutId}
    <div
      id="sumup-checkout"
      data-checkout-id="{$sumupCheckoutId|escape:'html'}"
      data-cancel-url="{$sumupCancelUrl|escape:'html'}"
      data-amount="{$sumupAmount|escape:'html'}"
      data-currency="{$sumupCurrency|escape:'html'}"
      data-locale="{$sumupLocale|escape:'html'}"
      data-mode="{$sumupCheckoutMode|escape:'html'}"
      data-public-key="{$sumupPublicMerchantKey|escape:'html'}"
      data-country-code="{$sumupMerchantCountryCode|escape:'html'}"
      data-browser-return-url="{$sumupBrowserReturnUrl|escape:'html'}"
    >
    </div>
  {/if}
</div>
