<div class="card mt-2">
  <div class="card-header">
    <h3 class="card-header-title">{l s='Crypto payment (PayGate.to)' d='Modules.Cryptopaymentgateway.Admin'}</h3>
  </div>
  <div class="card-body">
    <table class="table">
      <tr><th style="width:30%">{l s='Method' d='Modules.Cryptopaymentgateway.Admin'}</th><td>{if $pgc_payment.mode == 'multi'}{l s='Multicoin (hosted checkout page)' d='Modules.Cryptopaymentgateway.Admin'}{else}{l s='Individual coin (QR code on your store)' d='Modules.Cryptopaymentgateway.Admin'} &middot; {$pgc_payment.coin_label|escape:'htmlall':'UTF-8'}{/if}</td></tr>
      <tr><th>{l s='Payment status' d='Modules.Cryptopaymentgateway.Admin'}</th><td><strong>{if $pgc_payment.status == 'paid'}{l s='Paid' d='Modules.Cryptopaymentgateway.Admin'}{elseif $pgc_payment.status == 'failed'}{l s='Underpaid / failed' d='Modules.Cryptopaymentgateway.Admin'}{else}{l s='Awaiting payment' d='Modules.Cryptopaymentgateway.Admin'}{/if}</strong></td></tr>
      {if $pgc_payment.address_in}<tr><th>{l s='Payment address' d='Modules.Cryptopaymentgateway.Admin'}</th><td><code>{$pgc_payment.address_in|escape:'htmlall':'UTF-8'}</code></td></tr>{/if}
      {if $pgc_payment.display_total}<tr><th>{l s='Amount requested' d='Modules.Cryptopaymentgateway.Admin'}</th><td>{$pgc_payment.display_total|escape:'htmlall':'UTF-8'} {$pgc_payment.coin|upper|escape:'htmlall':'UTF-8'}</td></tr>{/if}
      <tr><th>{l s='Order total' d='Modules.Cryptopaymentgateway.Admin'}</th><td>{$pgc_payment.amount|string_format:'%.2f'} {$pgc_payment.currency_iso|escape:'htmlall':'UTF-8'}</td></tr>
      {if $pgc_payment.value_coin}<tr><th>{l s='Last payment' d='Modules.Cryptopaymentgateway.Admin'}</th><td>{$pgc_payment.value_coin|escape:'htmlall':'UTF-8'} {$pgc_payment.paid_coin|upper|escape:'htmlall':'UTF-8'}</td></tr>{/if}
      {if $pgc_txids}<tr><th>{l s='Total received' d='Modules.Cryptopaymentgateway.Admin'}</th><td>~{$pgc_payment.received_total|string_format:'%.2f'} {$pgc_payment.currency_iso|escape:'htmlall':'UTF-8'}</td></tr>{/if}
      {if $pgc_payment.txid_in}<tr><th>{l s='TXID (customer payment)' d='Modules.Cryptopaymentgateway.Admin'}</th><td><code>{$pgc_payment.txid_in|escape:'htmlall':'UTF-8'}</code></td></tr>{/if}
      {if count($pgc_txids) > 1}<tr><th>{l s='Payment TXIDs' d='Modules.Cryptopaymentgateway.Admin'}</th><td style="word-break:break-all">{foreach from=$pgc_txids item=txid name=txids}<code>{$txid|escape:'htmlall':'UTF-8'}</code>{if !$smarty.foreach.txids.last}<br>{/if}{/foreach}</td></tr>{/if}
      {if $pgc_payment.txid_out}<tr><th>{l s='TXID (payout to you)' d='Modules.Cryptopaymentgateway.Admin'}</th><td><code>{$pgc_payment.txid_out|escape:'htmlall':'UTF-8'}</code></td></tr>{/if}
      {if $pgc_payment.ipn_token}<tr><th>{l s='IPN token (for PayGate support)' d='Modules.Cryptopaymentgateway.Admin'}</th><td style="word-break:break-all"><code>{$pgc_payment.ipn_token|escape:'htmlall':'UTF-8'}</code></td></tr>{/if}
      <tr><th>{l s='Last update' d='Modules.Cryptopaymentgateway.Admin'}</th><td>{$pgc_payment.date_upd|escape:'htmlall':'UTF-8'}</td></tr>
    </table>
  </div>
</div>
