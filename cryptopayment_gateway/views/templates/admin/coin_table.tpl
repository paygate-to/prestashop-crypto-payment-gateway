{if $pgc_coin_list_error}
  <div class="alert alert-warning">{l s='Could not load the coin list from PayGate right now. Please reload this page in a moment, or check your server\'s outbound HTTPS connection and the API Domain above. Coins you already set up are shown below and kept when you save.' d='Modules.Cryptopaymentgateway.Admin'}</div>
{/if}
{if $pgc_coin_rows}
  <p class="help-block">{l s='The coin list and icons are loaded live from PayGate. Tick a coin and enter the wallet that should receive its payouts. Coins without a wallet are not offered at checkout.' d='Modules.Cryptopaymentgateway.Admin'}</p>
  <div style="max-height:520px;overflow:auto">
    <table class="table table-condensed">
      <thead>
        <tr>
          <th style="width:1%">{l s='Enable' d='Modules.Cryptopaymentgateway.Admin'}</th>
          <th>{l s='Coin' d='Modules.Cryptopaymentgateway.Admin'}</th>
          <th>{l s='Payout Wallet Address' d='Modules.Cryptopaymentgateway.Admin'}</th>
        </tr>
      </thead>
      <tbody>
        {foreach from=$pgc_coin_rows item=coin}
          <tr>
            <td class="text-center">
              <input type="hidden" name="pgc_coins[{$coin.id|escape:'htmlall':'UTF-8'}][enabled]" value="0">
              <input type="checkbox" name="pgc_coins[{$coin.id|escape:'htmlall':'UTF-8'}][enabled]" value="1"{if $coin.enabled} checked{/if}>
            </td>
            <td style="white-space:nowrap">
              {if $coin.logo}<img src="{$coin.logo|escape:'htmlall':'UTF-8'}" alt="" width="20" height="20" loading="lazy" style="border-radius:50%;vertical-align:middle;margin-right:6px">{/if}{$coin.label|escape:'htmlall':'UTF-8'} <code>{$coin.id|escape:'htmlall':'UTF-8'}</code>
            </td>
            <td><input type="text" name="pgc_coins[{$coin.id|escape:'htmlall':'UTF-8'}][wallet]" value="{$coin.wallet|escape:'htmlall':'UTF-8'}" placeholder="{l s='Payout Wallet Address' d='Modules.Cryptopaymentgateway.Admin'}" autocomplete="off" spellcheck="false"></td>
          </tr>
        {/foreach}
      </tbody>
    </table>
  </div>
{/if}
