{if !empty($processors)}
  <div class="crm-block crm-form-block">
    <h3>{ts domain="sumup-payment-processor"}Select a SumUp payment processor{/ts}</h3>
    <table class="selector row-highlight">
      <thead><tr><th>{ts domain="sumup-payment-processor"}Processor{/ts}</th><th>{ts domain="sumup-payment-processor"}Environment{/ts}</th><th></th></tr></thead>
      <tbody>
      {foreach from=$processors item=item}
        <tr>
          <td>{$item.name|escape}</td>
          <td>{if $item.is_test}{ts domain="sumup-payment-processor"}Test{/ts}{else}{ts domain="sumup-payment-processor"}Live{/ts}{/if}</td>
          <td><a class="button" href="{$item.oauth_url|escape}">{ts domain="sumup-payment-processor"}Open connection{/ts}</a></td>
        </tr>
      {/foreach}
      </tbody>
    </table>
  </div>
{elseif !empty($processor)}
  <div class="crm-block crm-form-block">
    <h3>{$processor.name|escape} — {if $processor.is_test}{ts domain="sumup-payment-processor"}Test{/ts}{else}{ts domain="sumup-payment-processor"}Live{/ts}{/if}</h3>
    {if $connection}
      <p class="status success">{ts domain="sumup-payment-processor" 1=$connection.merchant_code}Connected to merchant %1.{/ts}</p>
      <p>{ts domain="sumup-payment-processor" 1=$connection.linked_at}Connected on: %1{/ts}</p>
      <p>{ts domain="sumup-payment-processor" 1=$connection.expires_at}Access token expiry timestamp: %1{/ts}</p>
      {if $missingScopes}
        <p class="status warning">{ts domain="sumup-payment-processor" 1=$missingScopes|implode:', '}Connection established, but these scopes are not granted yet: %1.{/ts}</p>
      {/if}
      <p><a class="button" href="{$connectUrl|escape}">{ts domain="sumup-payment-processor"}Reconnect to SumUp{/ts}</a></p>
      <form method="post" action="{$formAction|escape}">
        <input type="hidden" name="sumup_oauth_form_token" value="{$formToken|escape}">
        <input type="hidden" name="sumup_oauth_form_action" value="disconnect">
        <button class="button" type="submit">{ts domain="sumup-payment-processor"}Disconnect SumUp{/ts}</button>
      </form>
    {else}
      <p class="status">{ts domain="sumup-payment-processor"}No SumUp merchant is connected to this processor.{/ts}</p>
      <p class="status warning">{ts domain="sumup-payment-processor"}SumUp determines whether the authorized merchant is sandbox or live. Verify the merchant code before initiating a payment.{/ts}</p>
      <p><a class="button crm-form-submit default" href="{$connectUrl|escape}">{ts domain="sumup-payment-processor"}Connect to SumUp{/ts}</a></p>
    {/if}
  </div>
{/if}
