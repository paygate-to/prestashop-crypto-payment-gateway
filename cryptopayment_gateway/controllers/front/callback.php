<?php
/**
 * Payment confirmation from PayGate (individual coins and Multicoin).
 *
 * Order of checks: per-cart nonce, then PayGate's RSA signature over the exact callback URL, and
 * only then any payment value. Every parameter is read from the signed query string itself.
 * Payments add up: the order is paid once the coin amount quoted to the customer has arrived within
 * 3 hours (same coin), or once the total received, valued in the order currency, reaches the order
 * total x tolerance (+ estimated blockchain fee when the customer pays fees). Answers plain "ok" once
 * handled so PayGate stops retrying. Still answers while the shop is in maintenance mode.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptopayment_gatewayCallbackModuleFrontController extends ModuleFrontController
{
    /** The coin amount shown to the customer is honoured for this long (seconds), whatever the rate does. */
    const RATE_LOCK = 10800;

    /** @var Cryptopayment_gateway */
    public $module;

    /** PayGate must reach the callback even in maintenance mode or from a restricted country. */
    protected function displayMaintenancePage()
    {
    }

    protected function displayRestrictedCountryPage()
    {
    }

    public function postProcess()
    {
        $params = PaygateCryptoApi::callbackParams();
        $idCart = isset($params['id_cart']) && is_string($params['id_cart']) ? (int) $params['id_cart'] : 0;
        $nonce = isset($params['nonce']) && is_string($params['nonce']) ? $params['nonce'] : '';
        $payment = $this->module->getPaymentByCart($idCart);

        if (!$payment) {
            $this->reply(404, 'Unknown order');
        }

        if ($nonce === '' || !hash_equals((string) $payment['nonce'], $nonce)) {
            $this->reply(403, 'Invalid nonce');
        }

        $check = PaygateCryptoApi::verifyCallback((string) $payment['callback_url'], _PS_MODULE_DIR_ . $this->module->name . '/keys/');

        if (!$check['verified']) {
            PrestaShopLogger::addLog('PayGate crypto: rejected callback for cart ' . $idCart . ' (' . $check['reason'] . '). No order status was changed.', 2, null, 'Cart', $idCart, true);
            $this->reply(403, 'Invalid callback signature');
        }

        // Unconfirmed (pending) transactions never change the order; the confirmed callback follows.
        if (isset($params['pending']) && $params['pending'] === '1') {
            $this->reply(200, 'ok');
        }

        $paidCoin = isset($params['coin']) && is_string($params['coin']) ? strtolower($params['coin']) : '';
        $valueCoin = isset($params['value_coin']) && is_string($params['value_coin']) ? $params['value_coin'] : '';
        $txidIn = isset($params['txid_in']) && is_string($params['txid_in']) ? $params['txid_in'] : '';
        $txidOut = isset($params['txid_out']) && is_string($params['txid_out']) && PaygateCryptoApi::isTxid($params['txid_out']) ? $params['txid_out'] : '';

        if (!PaygateCryptoApi::isCoinId($paidCoin) || !preg_match('/^\d{1,20}(\.\d{1,18})?$/', $valueCoin) || (float) $valueCoin <= 0 || !PaygateCryptoApi::isTxid($txidIn)) {
            $this->reply(400, 'Invalid payment data');
        }

        // Already settled, or this exact transaction was already counted: nothing to do.
        if ($payment['status'] === 'paid' || in_array($txidIn, explode(',', (string) $payment['txids']), true)) {
            $this->reply(200, 'ok');
        }

        // PrestaShop may split one cart into several orders; all of them are updated.
        $orders = $this->module->getCartOrders($idCart);

        if (!$orders || !(int) $payment['id_order']) {
            // The order is created right after the payment address; ask PayGate to retry.
            $this->reply(409, 'Order not ready, please retry');
        }

        $api = new PaygateCryptoApi((string) $payment['api_host']);
        $currency = (string) $payment['currency_iso'];
        $path = PaygateCryptoApi::coinPath($paidCoin);

        $info = $api->get($path . '/info.php');

        if (!isset($info['prices'][$currency]) || !is_numeric($info['prices'][$currency])) {
            $this->reply(502, 'Could not value the payment, please retry');
        }

        $valueFiat = (float) $valueCoin * (float) $info['prices'][$currency];
        $required = (float) $payment['amount'] * (float) $payment['tolerance'];

        if ((int) $payment['fees']) {
            $fees = $api->get($path . '/fees.php');

            if (!isset($fees['estimated_cost_currency'][$currency]) || !is_numeric($fees['estimated_cost_currency'][$currency])) {
                $this->reply(502, 'Could not value the payment, please retry');
            }

            $required += (float) $fees['estimated_cost_currency'][$currency];
        }

        $sameCoin = $payment['mode'] === 'coin' && $paidCoin === (string) $payment['coin'];

        $added = $this->module->addTransaction($idCart, [
            'paid_coin' => $paidCoin,
            'value_coin' => $valueCoin,
            'value_fiat' => $valueFiat,
            'same_coin' => $sameCoin,
            'txid_in' => $txidIn,
            'txid_out' => $txidOut,
        ]);

        if (!$added) {
            // Counted by a concurrent callback, or the order was paid meanwhile.
            $this->reply(200, 'ok');
        }

        $payment = $this->module->getPaymentByCart($idCart);
        $received = (float) $payment['received_total'];

        // Rate lock: the exact coin amount quoted to the customer is honoured for 3 hours.
        $locked = $sameCoin && (int) $payment['quote_age'] <= self::RATE_LOCK && (float) $payment['received_coin'] + 0.000000005 >= (float) $payment['display_total'] * (float) $payment['tolerance'];

        $notice = '';

        if ($payment['mode'] === 'coin' && !$sameCoin) {
            $notice = sprintf('[Notice] Customer paid in %s instead of %s; verifying the received value. ', strtoupper($paidCoin), $payment['coin_label']);
        }

        if ($locked || $received + 0.00001 >= $required) {
            if ($this->module->setPaid($idCart)) {
                $message = $notice . sprintf('[Payment completed] Customer sent %s %s (total received ~%.2f %s). TXID: %s', $valueCoin, strtoupper($paidCoin), $received, $currency, $txidIn);

                foreach ($orders as $order) {
                    $this->changeState($order, (int) Configuration::get('PS_OS_PAYMENT'), $message);
                }
            }
        } else {
            $this->module->setUnderpaid($idCart);

            $message = $notice . sprintf('[Payment underpaid] Received %s %s (~%.2f %s); total received ~%.2f %s, required minimum: %.2f %s. TXID: %s. The customer can send the rest to the same address.', $valueCoin, strtoupper($paidCoin), $valueFiat, $currency, $received, $currency, $required, $currency, $txidIn);

            foreach ($orders as $order) {
                $this->changeState($order, (int) Configuration::get('PS_OS_ERROR'), $message);
            }
        }

        $this->reply(200, 'ok');
    }

    /**
     * Change the order state (with the state's e-mail) and keep a private note for the merchant.
     *
     * An order the merchant already moved on to a paid, shipped or delivered state keeps that state;
     * the outcome is only added as a note.
     */
    private function changeState(Order $order, $idState, $message)
    {
        $current = (int) $order->getCurrentState();

        if ($current !== $idState) {
            $state = new OrderState($current);

            if (Validate::isLoadedObject($state) && ($state->paid || $state->shipped || $state->delivered)) {
                $message .= ' [Notice] The order status was left as set by the store.';
            } else {
                $history = new OrderHistory();
                $history->id_order = (int) $order->id;
                $history->changeIdOrderState($idState, (int) $order->id);
                $history->addWithemail(true);
            }
        }

        $note = new Message();
        $note->id_order = (int) $order->id;
        $note->id_cart = (int) $order->id_cart;
        $note->id_customer = (int) $order->id_customer;
        $note->message = Tools::substr(strip_tags($message), 0, 1600);
        $note->private = true;
        $note->add();
    }

    private function reply($code, $text)
    {
        http_response_code((int) $code);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
        echo $text;
        exit;
    }
}
