<form action="{$pgc_action|escape:'htmlall':'UTF-8'}" method="post" id="pgc-coin-form">
  <input type="hidden" name="pgc_mode" value="coin">
  {if $pgc_description_html}<p>{$pgc_description_html nofilter}</p>{/if}
  <p><strong>{l s='Select the coin you want to pay with:' d='Modules.Cryptopaymentgateway.Shop'}</strong></p>
  <div class="pgc-coins">
    {foreach from=$pgc_coins item=coin name=coins}
      <label class="pgc-coin" style="display:flex;align-items:center;gap:8px;margin:0 0 6px;padding:8px 10px;border:1px solid #ddd;border-radius:6px;cursor:pointer;font-weight:normal">
        <input type="radio" name="pgc_coin" value="{$coin.id|escape:'htmlall':'UTF-8'}"{if $smarty.foreach.coins.first} checked{/if}>
        {if $coin.logo}<img src="{$coin.logo|escape:'htmlall':'UTF-8'}" alt="" width="22" height="22" loading="lazy" style="border-radius:50%">{/if}
        <span>{$coin.label|escape:'htmlall':'UTF-8'}</span>
      </label>
    {/foreach}
  </div>
</form>
