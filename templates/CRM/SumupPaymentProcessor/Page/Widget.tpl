<div class="crm-block crm-sumup-checkout-block">
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
      data-wallets-allowed="{if $sumupWalletsAllowed}1{else}0{/if}"
      data-browser-return-url="{$sumupBrowserReturnUrl|escape:'html'}"
    >
    </div>
  {/if}
</div>
