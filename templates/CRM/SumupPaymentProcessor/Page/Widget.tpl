<div class="crm-block crm-sumup-checkout-block">
  {if $sumupPaid}
    <div style="text-align: center; padding: 2.5rem 1rem;">
      <div style="font-size: 3.5rem; color: #256f3a; margin-bottom: 1rem;">
        <i class="crm-i fa-check-circle"></i>
      </div>
      <h2 style="font-size: 1.6rem; font-weight: 700; margin-bottom: 0.5rem;">{ts}Payment Confirmed!{/ts}</h2>
      <p style="color: #5f6368; font-size: 1rem; margin-bottom: 1.5rem;">
        {ts}Thank you! Your payment has been approved and recorded successfully.{/ts}
      </p>
      <div style="background: #f7f7f5; border: 1px solid #dededb; border-radius: 0.75rem; padding: 0.85rem 1.25rem; display: inline-block;">
        <span style="font-size: 1.1rem; font-weight: 600;">{$sumupAmount|escape:'html'} {$sumupCurrency|escape:'html'}</span>
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
      data-country-code="{$sumupMerchantCountryCode|escape:'html'}"
      data-wallets-allowed="{if $sumupWalletsAllowed}1{else}0{/if}"
      data-browser-return-url="{$sumupBrowserReturnUrl|escape:'html'}"
    >
    </div>
  {/if}
</div>
