<section id="pgc-payment" class="card card-block" style="max-width:560px;margin:0 auto 20px;text-align:center">
  {if $pgc_payment.mode == 'coin'}
    <h3>{l s='Please complete your payment' d='Modules.Cryptopaymentgateway.Shop'}</h3>
    <div id="pgc-details"{if $pgc_payment.status == 'paid'} style="display:none"{/if}>
      {if $pgc_payment.qr_code}
        <img src="data:image/png;base64,{$pgc_payment.qr_code|escape:'htmlall':'UTF-8'}" alt="{$pgc_payment.address_in|escape:'htmlall':'UTF-8'}" style="width:80%;max-width:280px;height:auto;margin:0 auto 12px;display:block">
      {/if}
      <p>{$pgc_send_html nofilter}</p>
      <div class="input-group" style="margin-bottom:12px">
        <input type="text" id="pgc-address" class="form-control" style="text-align:center;font-family:monospace" value="{$pgc_payment.address_in|escape:'htmlall':'UTF-8'}" readonly>
        <span class="input-group-btn"><button type="button" id="pgc-copy" class="btn btn-primary" data-copied="{l s='Copied' d='Modules.Cryptopaymentgateway.Shop'}">{l s='Copy' d='Modules.Cryptopaymentgateway.Shop'}</button></span>
      </div>
      <p class="small text-muted">{l s='Send the exact amount of the selected coin on the right network. This amount is guaranteed for 3 hours. Your order is updated automatically once the payment is confirmed on the blockchain.' d='Modules.Cryptopaymentgateway.Shop'}</p>
    </div>
  {else}
    <h3>{l s='Crypto payment' d='Modules.Cryptopaymentgateway.Shop'}</h3>
    {if $pgc_payment.status != 'paid' && $pgc_hosted_url}
      <p>{l s='Complete your payment on the secure crypto checkout page.' d='Modules.Cryptopaymentgateway.Shop'}</p>
      <p><a class="btn btn-primary" href="{$pgc_hosted_url|escape:'htmlall':'UTF-8'}" rel="noopener">{l s='Go to the payment page' d='Modules.Cryptopaymentgateway.Shop'}</a></p>
    {/if}
  {/if}
  <div id="pgc-status" class="alert {if $pgc_payment.status == 'paid'}alert-success{elseif $pgc_payment.status == 'failed'}alert-danger{else}alert-info{/if}" role="status" data-status="{$pgc_payment.status|escape:'htmlall':'UTF-8'}" data-url="{$pgc_status_url|escape:'htmlall':'UTF-8'}" data-error="{l s='Could not check the payment status. Please refresh the page.' d='Modules.Cryptopaymentgateway.Shop'}">{$pgc_message|escape:'htmlall':'UTF-8'}</div>
</section>
<script>
{literal}
(function () {
  var box = document.getElementById('pgc-status');
  var copy = document.getElementById('pgc-copy');
  if (copy) {
    copy.addEventListener('click', function () {
      var input = document.getElementById('pgc-address');
      input.select();
      (navigator.clipboard ? navigator.clipboard.writeText(input.value) : Promise.reject()).catch(function () { document.execCommand('copy'); }).finally(function () { copy.textContent = copy.getAttribute('data-copied'); });
    });
  }
  var status = box.getAttribute('data-status');
  var shown = box.textContent;
  function show(state, message) {
    box.className = 'alert ' + (state === 'paid' ? 'alert-success' : (state === 'failed' ? 'alert-danger' : 'alert-warning'));
    box.textContent = shown = message;
    var details = document.getElementById('pgc-details');
    if (state === 'paid' && details) { details.style.display = 'none'; }
  }
  function check() {
    fetch(box.getAttribute('data-url'), {cache: 'no-store', credentials: 'same-origin'}).then(function (r) { return r.json(); }).then(function (json) {
      if (json.message && json.message !== shown) { status = json.status; show(status, json.message); }
      // An underpaid order can still be completed with a further payment: keep checking.
      if (status !== 'paid') { setTimeout(check, 30000); }
    }).catch(function () { show('error', box.getAttribute('data-error')); setTimeout(check, 60000); });
  }
  if (status !== 'paid') { setTimeout(check, 30000); }
})();
{/literal}
</script>
