<div class="crm-block crm-sumup-ui crm-sumup-ui--standalone crm-sumup-checkout-block">
  {if $sumupPaid}
    <div class="crm-sumup-state crm-sumup-state--success">
      <div class="crm-sumup-state__icon">
        <i class="crm-i fa-check-circle"></i>
      </div>
      <h2>{ts}Payment Confirmed!{/ts}</h2>
      <p class="crm-sumup-state__message">
        {ts}Thank you! Your payment has been approved and recorded successfully.{/ts}
      </p>
      <div class="crm-sumup-state__amount">
        {$sumupAmount|escape:'html'} {$sumupCurrency|escape:'html'}
      </div>
    </div>
  {elseif $sumupCheckoutId}
    <div
      id="sumup-checkout"
      data-checkout-id="{$sumupCheckoutId|escape:'html'}"
      data-cancel-url="{$sumupCancelUrl|escape:'html'}"
      data-amount="{$sumupAmount|escape:'html'}"
      data-currency="{$sumupCurrency|escape:'html'}"
      data-locale="{$sumupLocale|escape:'html'}"
      data-mode="{$sumupCheckoutMode|escape:'html'}"
      data-public-key="{$sumupPublicMerchantKey|escape:'html'}"
      data-business-name="{$sumupBusinessName|escape:'html'}"
      data-country-code="{$sumupMerchantCountryCode|escape:'html'}"
      data-wallets-allowed="{if $sumupWalletsAllowed}1{else}0{/if}"
      data-accepted-cards="{$sumupAcceptedCards|@json_encode|escape:'html'}"
      data-browser-return-url="{$sumupBrowserReturnUrl|escape:'html'}"
    >
    </div>
  {/if}
</div>
