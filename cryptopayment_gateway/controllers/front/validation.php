<?php
/**
 * Creates the crypto payment for the cart, then the order.
 *
 * Individual coin: convert -> optional blockchain fees -> coin minimum -> pay-in address -> QR code,
 * then the order is created in "Awaiting crypto payment" and the customer sees the QR code on the
 * order confirmation page. Multicoin: a hosted session is created, then the order, then the customer
 * is sent to the hosted checkout page. Nothing is ordered if PayGate cannot create the payment.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptopayment_gatewayValidationModuleFrontController extends ModuleFrontController
{
    /** @var Cryptopayment_gateway */
    public $module;

    public $ssl = true;

    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$cart || !$cart->id || $cart->id_customer == 0 || $cart->id_address_delivery == 0 || $cart->id_address_invoice == 0 || !$this->module->active) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        $authorized = false;

        foreach (Module::getPaymentModules() as $module) {
            if ($module['name'] == $this->module->name) {
                $authorized = true;
                break;
            }
        }

        $customer = new Customer((int) $cart->id_customer);

        if (!$authorized || !Validate::isLoadedObject($customer)) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        // Cart already ordered (page refreshed, back button): show that order, never a second one.
        $existingOrder = (int) Order::getIdByCartId((int) $cart->id);

        if ($existingOrder) {
            Tools::redirect($this->confirmationUrl($cart, $existingOrder, $customer));
        }

        $mode = Tools::getValue('pgc_mode') === 'multi' ? 'multi' : 'coin';
        $currency = new Currency((int) $cart->id_currency);
        $total = (float) $cart->getOrderTotal(true, Cart::BOTH);
        $existing = $this->module->getPaymentByCart((int) $cart->id);

        // One callback secret and status key for the life of the cart.
        $nonce = !empty($existing['nonce']) ? $existing['nonce'] : PaygateCryptoApi::token();
        $statusKey = !empty($existing['status_key']) ? $existing['status_key'] : PaygateCryptoApi::token();
        $callbackUrl = $this->context->link->getModuleLink($this->module->name, 'callback', ['id_cart' => (int) $cart->id, 'nonce' => $nonce], true);

        if ($mode === 'coin') {
            $result = $this->createCoinPayment($cart, $currency, $total, $nonce, $statusKey, $callbackUrl);
        } else {
            $result = $this->createMultiPayment($cart, $currency, $total, $nonce, $statusKey, $callbackUrl);
        }

        if (isset($result['error'])) {
            $this->errors[] = $result['error'];
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
        }

        try {
            $this->module->validateOrder(
                (int) $cart->id,
                (int) Configuration::get('PGC_OS_AWAITING'),
                $total,
                $result['payment_name'],
                null,
                [],
                (int) $currency->id,
                false,
                $customer->secure_key
            );
        } catch (Exception $e) {
            PrestaShopLogger::addLog('PayGate crypto: order creation failed for cart ' . (int) $cart->id . ': ' . $e->getMessage(), 3, null, 'Cart', (int) $cart->id, true);
            $this->errors[] = $this->module->getTranslator()->trans('Your order could not be created, please try again or contact us.', [], 'Modules.Cryptopaymentgateway.Shop');
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
        }

        $this->module->setPaymentOrder((int) $cart->id, (int) $this->module->currentOrder);

        if ($mode === 'multi') {
            Tools::redirect($this->module->hostedUrl($result['payment_token'], (bool) Configuration::get('PGC_FEES')));
        }

        Tools::redirect($this->confirmationUrl($cart, (int) $this->module->currentOrder, $customer));
    }

    /**
     * @return array payment_name, or error
     */
    private function createCoinPayment(Cart $cart, Currency $currency, $total, $nonce, $statusKey, $callbackUrl)
    {
        $t = $this->module->getTranslator();
        $coins = Configuration::get('PGC_COIN_ENABLED') ? $this->module->getEnabledCoins() : [];
        $coinId = (string) Tools::getValue('pgc_coin');

        if (!isset($coins[$coinId])) {
            return ['error' => $t->trans('Please select a valid cryptocurrency to pay with.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $coin = $coins[$coinId];
        $path = $coin['path'];
        $api = new PaygateCryptoApi((string) Configuration::get('PGC_API_DOMAIN'));
        $feesOn = (bool) Configuration::get('PGC_FEES');

        // 1) Order total in the selected coin.
        $convert = $api->get($path . '/convert.php', ['value' => number_format($total, 2, '.', ''), 'from' => strtolower($currency->iso_code)]);

        if (!isset($convert['value_coin']) || !is_numeric($convert['value_coin'])) {
            return ['error' => $t->trans('Payment could not be processed, please try again (unsupported store currency).', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $payin = (float) $convert['value_coin'];

        // 2) Optionally add the estimated blockchain fee.
        if ($feesOn) {
            $fees = $api->get($path . '/fees.php');

            if (!isset($fees['estimated_cost_currency']['USD']) || !is_numeric($fees['estimated_cost_currency']['USD'])) {
                return ['error' => $t->trans('Failed to get estimated blockchain fees, please try again.', [], 'Modules.Cryptopaymentgateway.Shop')];
            }

            $feeCoin = $api->get($path . '/convert.php', ['value' => (string) $fees['estimated_cost_currency']['USD'], 'from' => 'usd']);

            if (!isset($feeCoin['value_coin']) || !is_numeric($feeCoin['value_coin'])) {
                return ['error' => $t->trans('Failed to get estimated blockchain fees, please try again.', [], 'Modules.Cryptopaymentgateway.Shop')];
            }

            $payin += (float) $feeCoin['value_coin'];
        }

        // 3) Coin minimum: anything below it would be stuck at the pay-in address.
        $info = $api->get($path . '/info.php');

        if (!isset($info['minimum']) || !is_numeric($info['minimum'])) {
            return ['error' => $t->trans('Payment could not be processed, please try again (failed to fetch the coin minimum).', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        if ($payin < (float) $info['minimum']) {
            return ['error' => $t->trans('The order total is below the minimum allowed for the selected coin. Please choose another coin.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        // 4) Unique pay-in address bound to this cart's callback.
        $wallet = $api->get($path . '/wallet.php', ['address' => $coin['wallet'], 'callback' => $callbackUrl]);

        if (!isset($wallet['address_in']) || !is_string($wallet['address_in']) || !PaygateCryptoApi::isAddress($wallet['address_in'])) {
            return ['error' => $t->trans('Payment could not be processed due to incorrect payout wallet settings, please contact us.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        // 5) QR code of the pay-in address.
        $qr = $api->get($path . '/qrcode.php', ['address' => $wallet['address_in']]);

        if (!isset($qr['qr_code']) || !is_string($qr['qr_code']) || !preg_match('#^[A-Za-z0-9+/=]+$#', $qr['qr_code'])) {
            return ['error' => $t->trans('Unable to generate the payment QR code, please try again.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $label = PaygateCryptoApi::coinLabel($coin);

        $saved = $this->module->savePayment((int) $cart->id, [
            'mode' => 'coin',
            'coin' => $coinId,
            'coin_label' => $label,
            'address_in' => $wallet['address_in'],
            'ipn_token' => isset($wallet['ipn_token']) ? (string) $wallet['ipn_token'] : '',
            'display_total' => rtrim(rtrim(number_format($payin, 8, '.', ''), '0'), '.'),
            'qr_code' => $qr['qr_code'],
            'amount' => $total,
            'currency_iso' => $currency->iso_code,
            'tolerance' => $this->module->getTolerance(),
            'fees' => $feesOn ? 1 : 0,
            'api_host' => $api->getHost(),
            'nonce' => $nonce,
            'status_key' => $statusKey,
            'callback_url' => $callbackUrl,
        ]);

        if (!$saved) {
            return ['error' => $t->trans('A payment was already received for this cart. Please contact us.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $title = (string) Configuration::get('PGC_COIN_TITLE');

        return ['payment_name' => ($title !== '' ? $title : $this->module->displayName) . ' (' . $label . ')'];
    }

    /**
     * @return array payment_name + payment_token, or error
     */
    private function createMultiPayment(Cart $cart, Currency $currency, $total, $nonce, $statusKey, $callbackUrl)
    {
        $t = $this->module->getTranslator();
        $wallets = Configuration::get('PGC_MULTI_ENABLED') ? $this->module->getMultiWallets() : [];

        if (!$wallets) {
            return ['error' => $t->trans('No payout wallet is configured, please contact us.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $api = new PaygateCryptoApi((string) Configuration::get('PGC_API_DOMAIN'));

        $response = $api->postJson('multi-hosted-wallet.php', $wallets + [
            'fiat_amount' => round($total, 2),
            'fiat_currency' => $currency->iso_code,
            'callback' => $callbackUrl,
        ]);

        $token = isset($response['payment_token']) && is_string($response['payment_token']) ? $response['payment_token'] : '';

        if ($token === '' || !preg_match('/^[A-Za-z0-9%._~+\/=-]+$/', $token)) {
            return ['error' => $t->trans('Payment could not be initialised, please try again or contact us.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $saved = $this->module->savePayment((int) $cart->id, [
            'mode' => 'multi',
            'payment_token' => $token,
            'ipn_token' => isset($response['ipn_token']) ? (string) $response['ipn_token'] : '',
            'amount' => $total,
            'currency_iso' => $currency->iso_code,
            'tolerance' => $this->module->getTolerance(),
            'fees' => Configuration::get('PGC_FEES') ? 1 : 0,
            'api_host' => $api->getHost(),
            'nonce' => $nonce,
            'status_key' => $statusKey,
            'callback_url' => $callbackUrl,
        ]);

        if (!$saved) {
            return ['error' => $t->trans('A payment was already received for this cart. Please contact us.', [], 'Modules.Cryptopaymentgateway.Shop')];
        }

        $title = (string) Configuration::get('PGC_MULTI_TITLE');

        return ['payment_name' => $title !== '' ? $title : $this->module->displayName, 'payment_token' => $token];
    }

    private function confirmationUrl(Cart $cart, $idOrder, Customer $customer)
    {
        return $this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int) $cart->id,
            'id_module' => (int) $this->module->id,
            'id_order' => (int) $idOrder,
            'key' => $customer->secure_key,
        ]);
    }
}
