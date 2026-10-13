<?php
/**
 * Live payment status for the order confirmation page (JSON). Needs the cart id and its status key.
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

class Cryptopayment_gatewayStatusModuleFrontController extends ModuleFrontController
{
    /** @var Cryptopayment_gateway */
    public $module;

    public function postProcess()
    {
        $payment = $this->module->getPaymentByCart((int) Tools::getValue('id_cart'));
        $key = (string) Tools::getValue('key');

        if (!$payment || $key === '' || !hash_equals((string) $payment['status_key'], $key)) {
            $status = 'unknown';
            $message = '';
        } else {
            $status = (string) $payment['status'];
            $message = $this->module->statusMessage($payment);
        }

        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(['status' => $status, 'message' => $message]);
        exit;
    }
}
