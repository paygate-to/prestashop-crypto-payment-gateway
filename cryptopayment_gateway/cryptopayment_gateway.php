<?php
/**
 * Crypto Payment Gateway (PayGate.to) for PrestaShop.
 *
 * Two payment options, each can be enabled on its own:
 *  - Individual coins: the customer picks a coin at checkout; a unique pay-in address and QR code are
 *    created through PayGate and shown on your own order confirmation page.
 *  - Multicoin: the customer picks any enabled coin on the PayGate hosted checkout page.
 * Crypto is forwarded instantly to your own payout wallets, without KYC. Orders are confirmed by
 * PayGate's signed callback (controllers/front/callback.php).
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

require_once __DIR__ . '/classes/PaygateCryptoApi.php';

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

class Cryptopayment_gateway extends PaymentModule
{
    /** Payout wallet per network family accepted by crypto/multi-hosted-wallet.php */
    const MULTI_WALLETS = ['evm', 'btc', 'bitcoincash', 'ltc', 'doge', 'solana', 'trc20', 'xmr', 'zec', 'ton'];

    const TOLERANCES = ['1' => '0%', '0.99' => '1%', '0.98' => '2%', '0.97' => '3%', '0.96' => '4%', '0.95' => '5%', '0.94' => '6%', '0.93' => '7%', '0.92' => '8%', '0.91' => '9%', '0.90' => '10%'];

    public function __construct()
    {
        $this->name = 'cryptopayment_gateway';
        $this->tab = 'payments_gateways';
        $this->version = '1.0.0';
        $this->author = 'PayGate.to';
        $this->need_instance = 0;
        $this->bootstrap = true;
        $this->ps_versions_compliancy = ['min' => '1.7.7.0', 'max' => _PS_VERSION_];
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->controllers = ['validation', 'callback', 'status'];

        parent::__construct();

        $this->displayName = $this->trans('Crypto Payment Gateway (PayGate.to)', [], 'Modules.Cryptopaymentgateway.Admin');
        $this->description = $this->trans('Accept cryptocurrencies with instant payouts to your own wallets and without KYC. QR code on your own store, or the hosted multi-coin checkout page.', [], 'Modules.Cryptopaymentgateway.Admin');
        $this->confirmUninstall = $this->trans('Are you sure you want to uninstall? Crypto payment records of past orders are kept.', [], 'Modules.Cryptopaymentgateway.Admin');
    }

    public function isUsingNewTranslationSystem()
    {
        return true;
    }

    /* ---------------------------------------------------------------------
     * Install / uninstall
     * ------------------------------------------------------------------ */

    public function install()
    {
        return parent::install()
            && $this->registerHook('paymentOptions')
            && $this->registerHook('displayPaymentReturn')
            && $this->registerHook('displayOrderDetail')
            && $this->registerHook('displayAdminOrderMainBottom')
            && $this->installDb()
            && $this->installOrderState()
            && $this->installConfiguration();
    }

    /**
     * Settings are removed; the payment records table and the order state are kept, because past
     * orders refer to them.
     */
    public function uninstall()
    {
        foreach ($this->configurationKeys() as $key) {
            Configuration::deleteByName($key);
        }

        return parent::uninstall();
    }

    private function installDb()
    {
        return Db::getInstance()->execute('CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'cryptopayment_gateway` (
            `id_cart` INT(10) UNSIGNED NOT NULL,
            `id_order` INT(10) UNSIGNED NOT NULL DEFAULT 0,
            `mode` VARCHAR(8) NOT NULL,
            `coin` VARCHAR(80) NOT NULL DEFAULT \'\',
            `coin_label` VARCHAR(128) NOT NULL DEFAULT \'\',
            `address_in` VARCHAR(255) NOT NULL DEFAULT \'\',
            `payment_token` TEXT NOT NULL,
            `ipn_token` TEXT NOT NULL,
            `display_total` VARCHAR(40) NOT NULL DEFAULT \'\',
            `qr_code` MEDIUMTEXT NOT NULL,
            `amount` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `currency_iso` VARCHAR(3) NOT NULL DEFAULT \'\',
            `tolerance` DECIMAL(4,2) NOT NULL DEFAULT 0.99,
            `fees` TINYINT(1) NOT NULL DEFAULT 0,
            `api_host` VARCHAR(253) NOT NULL DEFAULT \'\',
            `nonce` VARCHAR(64) NOT NULL,
            `status_key` VARCHAR(64) NOT NULL,
            `callback_url` TEXT NOT NULL,
            `quoted_at` DATETIME NOT NULL,
            `status` VARCHAR(16) NOT NULL DEFAULT \'pending\',
            `received_total` DECIMAL(20,6) NOT NULL DEFAULT 0,
            `received_coin` DECIMAL(30,10) NOT NULL DEFAULT 0,
            `txids` TEXT NOT NULL,
            `paid_coin` VARCHAR(80) NOT NULL DEFAULT \'\',
            `value_coin` VARCHAR(40) NOT NULL DEFAULT \'\',
            `txid_in` VARCHAR(255) NOT NULL DEFAULT \'\',
            `txid_out` VARCHAR(255) NOT NULL DEFAULT \'\',
            `date_add` DATETIME NOT NULL,
            `date_upd` DATETIME NOT NULL,
            PRIMARY KEY (`id_cart`),
            KEY `id_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;');
    }

    /**
     * "Awaiting crypto payment" order state, created once and reused on reinstall.
     */
    private function installOrderState()
    {
        $idState = (int) Configuration::get('PGC_OS_AWAITING');

        if ($idState && Validate::isLoadedObject(new OrderState($idState))) {
            return true;
        }

        $state = new OrderState();
        $state->name = [];

        foreach (Language::getLanguages(false) as $language) {
            $state->name[(int) $language['id_lang']] = 'Awaiting crypto payment';
        }

        $state->module_name = $this->name;
        $state->color = '#4169E1';
        $state->send_email = false;
        $state->logable = false;
        $state->invoice = false;
        $state->hidden = false;
        $state->unremovable = true;
        $state->delivery = false;
        $state->shipped = false;
        $state->paid = false;
        $state->pdf_invoice = false;
        $state->pdf_delivery = false;

        if (!$state->add()) {
            return false;
        }

        return Configuration::updateValue('PGC_OS_AWAITING', (int) $state->id);
    }

    private function installConfiguration()
    {
        $defaults = [
            'PGC_COIN_ENABLED' => 0,
            'PGC_COIN_TITLE' => 'Cryptocurrency',
            'PGC_COIN_DESCRIPTION' => 'Pay with your preferred cryptocurrency.',
            'PGC_COINS' => '{}',
            'PGC_MULTI_ENABLED' => 0,
            'PGC_MULTI_TITLE' => 'Cryptocurrency (all coins)',
            'PGC_MULTI_DESCRIPTION' => 'Choose your coin on the secure crypto checkout page.',
            'PGC_MULTI_CHECKOUT_DOMAIN' => '',
            'PGC_MULTI_LOGO' => '',
            'PGC_MULTI_BACKGROUND' => '',
            'PGC_MULTI_THEME' => '',
            'PGC_MULTI_BUTTON' => '',
            'PGC_API_DOMAIN' => '',
            'PGC_TOLERANCE' => '0.99',
            'PGC_FEES' => 0,
        ];

        foreach (self::MULTI_WALLETS as $family) {
            $defaults['PGC_MULTI_WALLET_' . strtoupper($family)] = '';
        }

        foreach ($defaults as $key => $value) {
            if (!Configuration::hasKey($key) && !Configuration::updateValue($key, $value)) {
                return false;
            }
        }

        return true;
    }

    private function configurationKeys()
    {
        $keys = ['PGC_COIN_ENABLED', 'PGC_COIN_TITLE', 'PGC_COIN_DESCRIPTION', 'PGC_COINS', 'PGC_MULTI_ENABLED', 'PGC_MULTI_TITLE',
            'PGC_MULTI_DESCRIPTION', 'PGC_MULTI_CHECKOUT_DOMAIN', 'PGC_MULTI_LOGO', 'PGC_MULTI_BACKGROUND', 'PGC_MULTI_THEME',
            'PGC_MULTI_BUTTON', 'PGC_API_DOMAIN', 'PGC_TOLERANCE', 'PGC_FEES'];

        foreach (self::MULTI_WALLETS as $family) {
            $keys[] = 'PGC_MULTI_WALLET_' . strtoupper($family);
        }

        return $keys;
    }

    /* ---------------------------------------------------------------------
     * Configuration page
     * ------------------------------------------------------------------ */

    public function getContent()
    {
        $output = '';

        if (Tools::isSubmit('submitCryptoPayment')) {
            $errors = $this->saveConfiguration();

            if ($errors) {
                foreach ($errors as $error) {
                    $output .= $this->displayError($error);
                }
            } else {
                $output .= $this->displayConfirmation($this->trans('Settings updated.', [], 'Admin.Notifications.Success'));
            }
        }

        return $output . $this->renderForm();
    }

    /**
     * @return string[] errors
     */
    private function saveConfiguration()
    {
        $errors = [];

        $apiDomain = trim((string) Tools::getValue('PGC_API_DOMAIN'));
        $checkoutDomain = trim((string) Tools::getValue('PGC_MULTI_CHECKOUT_DOMAIN'));

        if ($apiDomain !== '' && PaygateCryptoApi::normaliseHost($apiDomain, '') === '') {
            $errors[] = $this->trans('API Domain: enter a domain name only, for example api-yourshop-1a2b3c4d5e6f7a8b9c0d1e2f.paygate.to', [], 'Modules.Cryptopaymentgateway.Admin');
        }

        if ($checkoutDomain !== '' && PaygateCryptoApi::normaliseHost($checkoutDomain, '') === '') {
            $errors[] = $this->trans('Custom Domain: enter a domain name only, for example checkout.yourdomain.com', [], 'Modules.Cryptopaymentgateway.Admin');
        }

        // Individual coins: only ids from the live list (or already saved) are kept, with a label/logo snapshot.
        $saved = $this->getCoinConfig();
        $map = $this->getCoinMap(true, $apiDomain) + $saved;
        $posted = Tools::getValue('pgc_coins');
        // No coin table in the form (coin list unavailable): the saved coins are kept as they are.
        $posted = is_array($posted) ? $posted : $saved;
        $coins = [];
        $usableCoin = false;

        foreach ($posted as $coinId => $row) {
            $coinId = (string) $coinId;

            if (!is_array($row) || !isset($map[$coinId]) || !PaygateCryptoApi::isCoinId($coinId)) {
                continue;
            }

            $wallet = trim((string) (isset($row['wallet']) ? $row['wallet'] : ''));
            $enabled = !empty($row['enabled']);

            if ($wallet === '' && !$enabled) {
                continue;
            }

            if ($wallet !== '' && !PaygateCryptoApi::isAddress($wallet)) {
                $errors[] = sprintf($this->trans('The payout wallet for %s looks invalid.', [], 'Modules.Cryptopaymentgateway.Admin'), htmlspecialchars(PaygateCryptoApi::coinLabel($map[$coinId]), ENT_QUOTES, 'UTF-8'));
            }

            $coins[$coinId] = [
                'enabled' => $enabled ? 1 : 0,
                'wallet' => $wallet,
                'label' => isset($map[$coinId]['label']) ? (string) $map[$coinId]['label'] : strtoupper($coinId),
                'chain' => isset($map[$coinId]['chain']) ? (string) $map[$coinId]['chain'] : '',
                'logo' => isset($map[$coinId]['logo']) ? (string) $map[$coinId]['logo'] : '',
            ];

            if ($enabled && $wallet !== '') {
                $usableCoin = true;
            }
        }

        if (Tools::getValue('PGC_COIN_ENABLED') && !$usableCoin) {
            $errors[] = $this->trans('Individual coins: enable at least one coin and enter its payout wallet address, otherwise no cryptocurrency can be offered at checkout.', [], 'Modules.Cryptopaymentgateway.Admin');
        }

        // Multicoin wallets.
        $wallets = [];
        $hasWallet = false;

        foreach (self::MULTI_WALLETS as $family) {
            $wallet = trim((string) Tools::getValue('PGC_MULTI_WALLET_' . strtoupper($family)));

            if ($wallet !== '' && !PaygateCryptoApi::isAddress($wallet)) {
                $errors[] = sprintf($this->trans('Multicoin: the %s wallet looks invalid.', [], 'Modules.Cryptopaymentgateway.Admin'), strtoupper($family));
            }

            $hasWallet = $hasWallet || $wallet !== '';
            $wallets['PGC_MULTI_WALLET_' . strtoupper($family)] = $wallet;
        }

        if (Tools::getValue('PGC_MULTI_ENABLED') && !$hasWallet) {
            $errors[] = $this->trans('Multicoin: enter at least one payout wallet, otherwise no cryptocurrency can be offered at checkout.', [], 'Modules.Cryptopaymentgateway.Admin');
        }

        $logo = trim((string) Tools::getValue('PGC_MULTI_LOGO'));

        if ($logo !== '' && !preg_match('#^https://[^\s"<>]+$#i', $logo)) {
            $errors[] = $this->trans('Logo URL: enter a full https:// image URL.', [], 'Modules.Cryptopaymentgateway.Admin');
        }

        $colors = [];

        foreach (['PGC_MULTI_BACKGROUND', 'PGC_MULTI_THEME', 'PGC_MULTI_BUTTON'] as $key) {
            $color = trim((string) Tools::getValue($key));

            if ($color !== '' && !preg_match('/^(#[0-9A-Fa-f]{3,8}|[A-Za-z]{3,20})$/', $color)) {
                $errors[] = $this->trans('Colors: enter a HEX color such as #1d4ed8 or a CSS color name.', [], 'Modules.Cryptopaymentgateway.Admin');
                break;
            }

            $colors[$key] = $color;
        }

        if ($errors) {
            return array_values(array_unique($errors));
        }

        $tolerance = (string) Tools::getValue('PGC_TOLERANCE');

        Configuration::updateValue('PGC_COIN_ENABLED', Tools::getValue('PGC_COIN_ENABLED') ? 1 : 0);
        Configuration::updateValue('PGC_COIN_TITLE', trim((string) Tools::getValue('PGC_COIN_TITLE')));
        // Descriptions are stored as HTML so line breaks are kept; they are always shown escaped.
        Configuration::updateValue('PGC_COIN_DESCRIPTION', trim((string) Tools::getValue('PGC_COIN_DESCRIPTION')), true);
        Configuration::updateValue('PGC_COINS', json_encode($coins));
        Configuration::updateValue('PGC_MULTI_ENABLED', Tools::getValue('PGC_MULTI_ENABLED') ? 1 : 0);
        Configuration::updateValue('PGC_MULTI_TITLE', trim((string) Tools::getValue('PGC_MULTI_TITLE')));
        Configuration::updateValue('PGC_MULTI_DESCRIPTION', trim((string) Tools::getValue('PGC_MULTI_DESCRIPTION')), true);
        Configuration::updateValue('PGC_MULTI_CHECKOUT_DOMAIN', $checkoutDomain === '' ? '' : PaygateCryptoApi::normaliseHost($checkoutDomain, ''));
        Configuration::updateValue('PGC_MULTI_LOGO', $logo);
        Configuration::updateValue('PGC_API_DOMAIN', $apiDomain === '' ? '' : PaygateCryptoApi::normaliseHost($apiDomain, ''));
        Configuration::updateValue('PGC_TOLERANCE', isset(self::TOLERANCES[$tolerance]) ? $tolerance : '0.99');
        Configuration::updateValue('PGC_FEES', Tools::getValue('PGC_FEES') ? 1 : 0);

        foreach ($wallets + $colors as $key => $value) {
            Configuration::updateValue($key, $value);
        }

        return [];
    }

    private function renderForm()
    {
        $switch = function ($name, $label, $desc = '') {
            return [
                'type' => 'switch',
                'label' => $label,
                'name' => $name,
                'is_bool' => true,
                'desc' => $desc,
                'values' => [
                    ['id' => $name . '_on', 'value' => 1, 'label' => $this->trans('Enabled', [], 'Admin.Global')],
                    ['id' => $name . '_off', 'value' => 0, 'label' => $this->trans('Disabled', [], 'Admin.Global')],
                ],
            ];
        };

        $toleranceOptions = [];

        foreach (self::TOLERANCES as $value => $label) {
            $toleranceOptions[] = ['id' => (string) $value, 'name' => $label];
        }

        $general = [
            'form' => [
                'legend' => ['title' => $this->trans('General settings', [], 'Modules.Cryptopaymentgateway.Admin'), 'icon' => 'icon-cogs'],
                'description' => $this->trans('Crypto is forwarded instantly to your own payout wallets, without KYC. These settings apply to both payment options below.', [], 'Modules.Cryptopaymentgateway.Admin'),
                'input' => [
                    [
                        'type' => 'text',
                        'label' => $this->trans('API Domain', [], 'Modules.Cryptopaymentgateway.Admin'),
                        'name' => 'PGC_API_DOMAIN',
                        'placeholder' => 'api.paygate.to',
                        'desc' => $this->trans('Your dedicated API endpoint from paygate.to (get it at paygate.to/white-label-api-custom-domain-guide/#dedicated-endpoint). Leave empty to use the shared api.paygate.to.', [], 'Modules.Cryptopaymentgateway.Admin'),
                    ],
                    [
                        'type' => 'select',
                        'label' => $this->trans('Underpaid Tolerance', [], 'Modules.Cryptopaymentgateway.Admin'),
                        'name' => 'PGC_TOLERANCE',
                        'options' => ['query' => $toleranceOptions, 'id' => 'id', 'name' => 'name'],
                        'desc' => $this->trans('Accept a payment that is slightly below the order total. Crypto rates move, so 1% or more is recommended.', [], 'Modules.Cryptopaymentgateway.Admin'),
                    ],
                    $switch('PGC_FEES', $this->trans('Customer Pays Blockchain Fees', [], 'Modules.Cryptopaymentgateway.Admin'), $this->trans('Add the estimated blockchain fee to the amount the customer must send.', [], 'Modules.Cryptopaymentgateway.Admin')),
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions'), 'name' => 'submitCryptoPayment'],
            ],
        ];

        $coin = [
            'form' => [
                'legend' => ['title' => $this->trans('Individual coins (QR code on your store)', [], 'Modules.Cryptopaymentgateway.Admin'), 'icon' => 'icon-qrcode'],
                'input' => [
                    $switch('PGC_COIN_ENABLED', $this->trans('Enable', [], 'Admin.Actions')),
                    [
                        'type' => 'text',
                        'label' => $this->trans('Title', [], 'Admin.Global'),
                        'name' => 'PGC_COIN_TITLE',
                    ],
                    [
                        'type' => 'textarea',
                        'label' => $this->trans('Description', [], 'Admin.Global'),
                        'name' => 'PGC_COIN_DESCRIPTION',
                        'rows' => 3,
                    ],
                    [
                        'type' => 'html',
                        'label' => $this->trans('Accepted Coins', [], 'Modules.Cryptopaymentgateway.Admin'),
                        'name' => 'pgc_coins',
                        'html_content' => $this->renderCoinTable(),
                    ],
                ],
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions'), 'name' => 'submitCryptoPayment'],
            ],
        ];

        $walletLabels = [
            'evm' => 'EVM Wallet (ETH, ERC20, BEP20, Polygon, Arbitrum, Base, Optimism, Avalanche-C)',
            'btc' => 'Bitcoin Wallet (BTC)',
            'bitcoincash' => 'Bitcoin Cash Wallet (BCH)',
            'ltc' => 'Litecoin Wallet (LTC)',
            'doge' => 'Dogecoin Wallet (DOGE)',
            'solana' => 'Solana Wallet (SOL and Solana tokens)',
            'trc20' => 'Tron Wallet (TRX, TRC20 USDT)',
            'xmr' => 'Monero Wallet (XMR)',
            'zec' => 'Zcash Wallet (ZEC)',
            'ton' => 'TON Wallet (Toncoin, TON USDT)',
        ];

        $multiInputs = [
            $switch('PGC_MULTI_ENABLED', $this->trans('Enable', [], 'Admin.Actions')),
            ['type' => 'text', 'label' => $this->trans('Title', [], 'Admin.Global'), 'name' => 'PGC_MULTI_TITLE'],
            ['type' => 'textarea', 'label' => $this->trans('Description', [], 'Admin.Global'), 'name' => 'PGC_MULTI_DESCRIPTION', 'rows' => 3],
            [
                'type' => 'text',
                'label' => $this->trans('Custom Domain', [], 'Modules.Cryptopaymentgateway.Admin'),
                'name' => 'PGC_MULTI_CHECKOUT_DOMAIN',
                'placeholder' => 'checkout.paygate.to',
                'desc' => $this->trans('Domain of the hosted checkout page. Leave empty to use checkout.paygate.to, or use your own domain from the custom domain guide at paygate.to/white-label-api-custom-domain-guide/', [], 'Modules.Cryptopaymentgateway.Admin'),
            ],
        ];

        foreach ($walletLabels as $family => $label) {
            $multiInputs[] = ['type' => 'text', 'label' => $this->trans($label, [], 'Modules.Cryptopaymentgateway.Admin'), 'name' => 'PGC_MULTI_WALLET_' . strtoupper($family)];
        }

        $multiInputs[] = ['type' => 'text', 'label' => $this->trans('Logo URL', [], 'Modules.Cryptopaymentgateway.Admin'), 'name' => 'PGC_MULTI_LOGO', 'placeholder' => 'https://', 'desc' => $this->trans('Optional: your logo on the hosted checkout page (https:// image URL).', [], 'Modules.Cryptopaymentgateway.Admin')];
        $multiInputs[] = ['type' => 'text', 'label' => $this->trans('Background Color', [], 'Modules.Cryptopaymentgateway.Admin'), 'name' => 'PGC_MULTI_BACKGROUND', 'placeholder' => '#ffffff'];
        $multiInputs[] = ['type' => 'text', 'label' => $this->trans('Theme Color', [], 'Modules.Cryptopaymentgateway.Admin'), 'name' => 'PGC_MULTI_THEME', 'placeholder' => '#1d4ed8'];
        $multiInputs[] = ['type' => 'text', 'label' => $this->trans('Button Color', [], 'Modules.Cryptopaymentgateway.Admin'), 'name' => 'PGC_MULTI_BUTTON', 'placeholder' => '#1d4ed8'];

        $multi = [
            'form' => [
                'legend' => ['title' => $this->trans('Multicoin (hosted checkout page)', [], 'Modules.Cryptopaymentgateway.Admin'), 'icon' => 'icon-globe'],
                'description' => $this->trans('One payment option for all supported coins: the customer picks a coin on the PayGate hosted checkout page. Fill in a payout wallet for each network you want to accept.', [], 'Modules.Cryptopaymentgateway.Admin'),
                'input' => $multiInputs,
                'submit' => ['title' => $this->trans('Save', [], 'Admin.Actions'), 'name' => 'submitCryptoPayment'],
            ],
        ];

        $helper = new HelperForm();
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->table = $this->table;
        $helper->identifier = $this->identifier;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name . '&tab_module=' . $this->tab . '&module_name=' . $this->name;
        $helper->submit_action = 'submitCryptoPayment';
        $helper->default_form_language = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');

        foreach ($this->configurationKeys() as $key) {
            if ($key !== 'PGC_COINS') {
                $helper->fields_value[$key] = Tools::getValue($key, Configuration::get($key));
            }
        }

        foreach (['PGC_COIN_DESCRIPTION', 'PGC_MULTI_DESCRIPTION'] as $key) {
            $helper->fields_value[$key] = html_entity_decode((string) $helper->fields_value[$key], ENT_QUOTES, 'UTF-8');
        }

        if ($helper->fields_value['PGC_TOLERANCE'] === '' || $helper->fields_value['PGC_TOLERANCE'] === false) {
            $helper->fields_value['PGC_TOLERANCE'] = '0.99';
        }

        return $helper->generateForm([$general, $coin, $multi]);
    }

    /**
     * Coin table for the configuration page: live coin list + saved wallets.
     */
    private function renderCoinTable()
    {
        $map = $this->getCoinMap(false, (string) Configuration::get('PGC_API_DOMAIN'));
        $saved = $this->getCoinConfig();
        $posted = Tools::isSubmit('submitCryptoPayment') && is_array(Tools::getValue('pgc_coins')) ? Tools::getValue('pgc_coins') : null;
        $rows = [];
        $listError = !$map;

        // Coin list unavailable: still show (and keep) the coins already configured.
        if (!$map) {
            foreach ($saved as $coinId => $coin) {
                if (is_array($coin) && PaygateCryptoApi::isCoinId((string) $coinId)) {
                    $map[(string) $coinId] = [
                        'label' => isset($coin['label']) ? (string) $coin['label'] : strtoupper((string) $coinId),
                        'chain' => isset($coin['chain']) ? (string) $coin['chain'] : '',
                        'logo' => isset($coin['logo']) ? (string) $coin['logo'] : '',
                    ];
                }
            }
        }

        foreach ($map as $coinId => $coin) {
            $row = $posted !== null ? (isset($posted[$coinId]) ? $posted[$coinId] : []) : (isset($saved[$coinId]) ? $saved[$coinId] : []);
            $rows[] = [
                'id' => $coinId,
                'label' => PaygateCryptoApi::coinLabel($coin),
                'logo' => $coin['logo'],
                'enabled' => !empty($row['enabled']),
                'wallet' => isset($row['wallet']) ? (string) $row['wallet'] : '',
            ];
        }

        $this->context->smarty->assign(['pgc_coin_rows' => $rows, 'pgc_coin_list_error' => $listError]);

        return $this->display(__FILE__, 'views/templates/admin/coin_table.tpl');
    }

    /* ---------------------------------------------------------------------
     * Coins and wallets
     * ------------------------------------------------------------------ */

    /**
     * Saved per-coin configuration (id => enabled, wallet, label, chain, logo).
     *
     * @return array
     */
    public function getCoinConfig()
    {
        $config = json_decode((string) Configuration::get('PGC_COINS'), true);

        return is_array($config) ? $config : [];
    }

    /**
     * Coins that are ticked AND have a payout wallet.
     *
     * @return array
     */
    public function getEnabledCoins()
    {
        $coins = [];

        foreach ($this->getCoinConfig() as $coinId => $coin) {
            if (is_array($coin) && PaygateCryptoApi::isCoinId($coinId) && !empty($coin['enabled']) && !empty($coin['wallet'])) {
                $coin['id'] = (string) $coinId;
                $coin['path'] = PaygateCryptoApi::coinPath($coinId);
                $coins[(string) $coinId] = $coin;
            }
        }

        return $coins;
    }

    /**
     * Configured Multicoin payout wallets, keyed by network family.
     *
     * @return array
     */
    public function getMultiWallets()
    {
        $wallets = [];

        foreach (self::MULTI_WALLETS as $family) {
            $wallet = trim((string) Configuration::get('PGC_MULTI_WALLET_' . strtoupper($family)));

            if ($wallet !== '') {
                $wallets[$family] = $wallet;
            }
        }

        return $wallets;
    }

    /**
     * Live coin list from PayGate, cached for an hour in the PrestaShop cache folder.
     *
     * @param bool $force
     * @param string $apiDomain
     *
     * @return array
     */
    public function getCoinMap($force, $apiDomain)
    {
        $api = new PaygateCryptoApi($apiDomain);
        $file = _PS_CACHE_DIR_ . 'cryptopayment_gateway_coins_' . md5($api->getHost()) . '.json';

        if (!$force && is_file($file) && filemtime($file) > time() - 3600) {
            $cached = json_decode((string) file_get_contents($file), true);

            if (is_array($cached) && $cached) {
                return $cached;
            }
        }

        $data = $api->get('info.php');
        $map = is_array($data) ? PaygateCryptoApi::flattenCoins($data) : [];

        if ($map) {
            @file_put_contents($file, json_encode($map));
        } elseif (is_file($file)) {
            // Keep the store working with the last known list.
            $cached = json_decode((string) file_get_contents($file), true);
            $map = is_array($cached) ? $cached : [];
        }

        return $map;
    }

    /**
     * Underpaid tolerance multiplier between 0.90 and 1.
     *
     * @return float
     */
    public function getTolerance()
    {
        $tolerance = (float) Configuration::get('PGC_TOLERANCE');

        return ($tolerance >= 0.9 && $tolerance <= 1) ? $tolerance : 0.99;
    }

    /* ---------------------------------------------------------------------
     * Payment records
     * ------------------------------------------------------------------ */

    /**
     * Payment record of a cart, or []. quote_age is the age of the quote in seconds.
     */
    public function getPaymentByCart($idCart)
    {
        if (!(int) $idCart) {
            return [];
        }

        $row = Db::getInstance()->getRow('SELECT *, TIMESTAMPDIFF(SECOND, `quoted_at`, NOW()) AS `quote_age` FROM `' . _DB_PREFIX_ . 'cryptopayment_gateway` WHERE `id_cart` = ' . (int) $idCart);

        return $row ?: [];
    }

    /**
     * All orders created from a cart (PrestaShop can split one cart into several orders).
     *
     * @return Order[]
     */
    public function getCartOrders($idCart)
    {
        $orders = [];
        $rows = Db::getInstance()->executeS('SELECT `id_order` FROM `' . _DB_PREFIX_ . 'orders` WHERE `id_cart` = ' . (int) $idCart . ' ORDER BY `id_order`');

        foreach ($rows ?: [] as $row) {
            $order = new Order((int) $row['id_order']);

            if (Validate::isLoadedObject($order)) {
                $orders[] = $order;
            }
        }

        return $orders;
    }

    /**
     * Create the payment record of a cart, or replace its quote. An existing record is only replaced
     * while no order exists for it and nothing has been received.
     *
     * @return bool
     */
    public function savePayment($idCart, array $data)
    {
        $db = Db::getInstance();
        $now = date('Y-m-d H:i:s');
        $fields = [
            'mode' => pSQL($data['mode']),
            'coin' => pSQL(isset($data['coin']) ? $data['coin'] : ''),
            'coin_label' => pSQL(isset($data['coin_label']) ? $data['coin_label'] : ''),
            'address_in' => pSQL(isset($data['address_in']) ? $data['address_in'] : ''),
            'payment_token' => pSQL(isset($data['payment_token']) ? $data['payment_token'] : ''),
            'ipn_token' => pSQL(isset($data['ipn_token']) ? $data['ipn_token'] : ''),
            'display_total' => pSQL(isset($data['display_total']) ? $data['display_total'] : ''),
            'qr_code' => pSQL(isset($data['qr_code']) ? $data['qr_code'] : ''),
            'amount' => (float) $data['amount'],
            'currency_iso' => pSQL($data['currency_iso']),
            'tolerance' => (float) $data['tolerance'],
            'fees' => (int) $data['fees'],
            'api_host' => pSQL($data['api_host']),
            'nonce' => pSQL($data['nonce']),
            'status_key' => pSQL($data['status_key']),
            'callback_url' => pSQL($data['callback_url']),
            'quoted_at' => ['type' => 'sql', 'value' => 'NOW()'],
            'date_upd' => $now,
        ];

        if ($this->getPaymentByCart($idCart)) {
            $db->update('cryptopayment_gateway', $fields, '`id_cart` = ' . (int) $idCart . ' AND `id_order` = 0 AND `status` = \'pending\' AND `txids` = \'\'');

            return $db->Affected_Rows() > 0;
        }

        $fields['id_cart'] = (int) $idCart;
        $fields['status'] = 'pending';
        $fields['txids'] = '';
        $fields['date_add'] = $now;

        return $db->insert('cryptopayment_gateway', $fields);
    }

    public function setPaymentOrder($idCart, $idOrder)
    {
        return Db::getInstance()->update('cryptopayment_gateway', ['id_order' => (int) $idOrder, 'date_upd' => date('Y-m-d H:i:s')], '`id_cart` = ' . (int) $idCart);
    }

    /**
     * Add a confirmed blockchain transaction to the payment record.
     *
     * Atomic: a transaction id is counted once, and nothing is added to a paid record, so a repeated
     * or concurrent callback can never credit an order twice. Payments add up, so a customer who sent
     * too little can send the rest to the same address.
     *
     * @param array $data paid_coin, value_coin, value_fiat, same_coin, txid_in, txid_out
     *
     * @return bool true when this call added the transaction
     */
    public function addTransaction($idCart, array $data)
    {
        $db = Db::getInstance();
        $txid = pSQL($data['txid_in']);

        $db->execute('UPDATE `' . _DB_PREFIX_ . 'cryptopayment_gateway` SET `received_total` = `received_total` + ' . (float) $data['value_fiat'] . ', `received_coin` = `received_coin` + ' . ($data['same_coin'] ? pSQL(number_format((float) $data['value_coin'], 10, '.', '')) : '0') . ', `txids` = IF(`txids` = \'\', \'' . $txid . '\', CONCAT(`txids`, \',\', \'' . $txid . '\')), `paid_coin` = \'' . pSQL($data['paid_coin']) . '\', `value_coin` = \'' . pSQL($data['value_coin']) . '\', `txid_in` = \'' . $txid . '\', `txid_out` = \'' . pSQL($data['txid_out']) . '\', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\' WHERE `id_cart` = ' . (int) $idCart . ' AND `status` <> \'paid\' AND FIND_IN_SET(\'' . $txid . '\', `txids`) = 0');

        return $db->Affected_Rows() > 0;
    }

    /**
     * Mark the record paid. Atomic: true only for the call that made the change.
     *
     * @return bool
     */
    public function setPaid($idCart)
    {
        $db = Db::getInstance();

        $db->execute('UPDATE `' . _DB_PREFIX_ . 'cryptopayment_gateway` SET `status` = \'paid\', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\' WHERE `id_cart` = ' . (int) $idCart . ' AND `status` <> \'paid\'');

        return $db->Affected_Rows() > 0;
    }

    /**
     * Mark the record underpaid (more can still be sent to the same address).
     */
    public function setUnderpaid($idCart)
    {
        return Db::getInstance()->execute('UPDATE `' . _DB_PREFIX_ . 'cryptopayment_gateway` SET `status` = \'failed\', `date_upd` = \'' . pSQL(date('Y-m-d H:i:s')) . '\' WHERE `id_cart` = ' . (int) $idCart . ' AND `status` = \'pending\'');
    }

    /**
     * Text for the payment status box shown to the customer (plain text, escaped where it is output).
     *
     * @return string
     */
    public function statusMessage(array $payment)
    {
        $translator = $this->getTranslator();

        if ($payment['status'] === 'paid') {
            return $translator->trans('Payment received. Thank you!', [], 'Modules.Cryptopaymentgateway.Shop');
        }

        if ($payment['status'] !== 'failed') {
            return $translator->trans('Waiting for payment', [], 'Modules.Cryptopaymentgateway.Shop');
        }

        $received = (float) $payment['received_coin'];
        $remaining = (float) $payment['display_total'] - $received;

        if ($payment['mode'] === 'coin' && $received > 0 && $remaining > 0) {
            return $translator->trans('We received %received% %coin%, which is less than the amount due. Please send the remaining %remaining% %coin% to the same address.', [
                '%received%' => $this->coinAmount($received),
                '%remaining%' => $this->coinAmount($remaining),
                '%coin%' => (string) $payment['coin_label'],
            ], 'Modules.Cryptopaymentgateway.Shop');
        }

        return $translator->trans('Payment failed: you may have sent an incorrect amount or token. Please contact us.', [], 'Modules.Cryptopaymentgateway.Shop');
    }

    private function coinAmount($value)
    {
        return rtrim(rtrim(number_format((float) $value, 8, '.', ''), '0'), '.');
    }

    /**
     * Payment option description, escaped, with its line breaks.
     *
     * @return string HTML
     */
    private function descriptionHtml($key)
    {
        return nl2br(htmlspecialchars(html_entity_decode((string) Configuration::get($key), ENT_QUOTES, 'UTF-8'), ENT_QUOTES, 'UTF-8'));
    }

    /* ---------------------------------------------------------------------
     * Hooks
     * ------------------------------------------------------------------ */

    public function hookPaymentOptions($params)
    {
        if (!$this->active || empty($params['cart']) || !$this->checkCurrency($params['cart'])) {
            return [];
        }

        $options = [];
        $logo = Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/multicoin.png');

        $coins = Configuration::get('PGC_COIN_ENABLED') ? $this->getEnabledCoins() : [];

        if ($coins) {
            $list = [];

            foreach ($coins as $coin) {
                $list[] = ['id' => $coin['id'], 'label' => PaygateCryptoApi::coinLabel($coin), 'logo' => isset($coin['logo']) ? $coin['logo'] : ''];
            }

            $this->context->smarty->assign([
                'pgc_action' => $this->context->link->getModuleLink($this->name, 'validation', [], true),
                'pgc_coins' => $list,
                'pgc_description_html' => $this->descriptionHtml('PGC_COIN_DESCRIPTION'),
            ]);

            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText((string) Configuration::get('PGC_COIN_TITLE') ?: $this->trans('Cryptocurrency', [], 'Modules.Cryptopaymentgateway.Shop'))
                ->setForm($this->fetch('module:' . $this->name . '/views/templates/hook/payment_option_coin.tpl'))
                ->setLogo($logo);
            $options[] = $option;
        }

        if (Configuration::get('PGC_MULTI_ENABLED') && $this->getMultiWallets()) {
            $description = $this->descriptionHtml('PGC_MULTI_DESCRIPTION');

            $option = new PaymentOption();
            $option->setModuleName($this->name)
                ->setCallToActionText((string) Configuration::get('PGC_MULTI_TITLE') ?: $this->trans('Cryptocurrency (all coins)', [], 'Modules.Cryptopaymentgateway.Shop'))
                ->setAction($this->context->link->getModuleLink($this->name, 'validation', [], true))
                ->setInputs(['pgc_mode' => ['name' => 'pgc_mode', 'type' => 'hidden', 'value' => 'multi']])
                ->setAdditionalInformation($description !== '' ? '<p>' . $description . '</p>' : '')
                ->setLogo($logo);
            $options[] = $option;
        }

        return $options;
    }

    public function checkCurrency($cart)
    {
        $currency = new Currency((int) $cart->id_currency);
        $moduleCurrencies = $this->getCurrency((int) $cart->id_currency);

        if (is_array($moduleCurrencies)) {
            foreach ($moduleCurrencies as $moduleCurrency) {
                if ((int) $currency->id === (int) $moduleCurrency['id_currency']) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Order confirmation page: QR code, amount and address (individual coin), or the link to the
     * hosted checkout page (Multicoin), with the live payment status.
     */
    public function hookDisplayPaymentReturn($params)
    {
        if (!$this->active || empty($params['order']) || $params['order']->module !== $this->name) {
            return '';
        }

        return $this->renderPaymentBox($params['order']);
    }

    /**
     * Customer account, order details: the same payment box, so the customer can come back to pay.
     */
    public function hookDisplayOrderDetail($params)
    {
        if (!$this->active || empty($params['order']) || $params['order']->module !== $this->name) {
            return '';
        }

        return $this->renderPaymentBox($params['order']);
    }

    private function renderPaymentBox(Order $order)
    {
        $payment = $this->getPaymentByCart((int) $order->id_cart);

        if (!$payment) {
            return '';
        }

        // Escaped sentence with the amount and coin in bold (output with nofilter).
        $send = htmlspecialchars($this->getTranslator()->trans('Please send %amount% %coin% to the following address:', [], 'Modules.Cryptopaymentgateway.Shop'), ENT_QUOTES, 'UTF-8');
        $send = strtr($send, [
            '%amount%' => '<strong>' . htmlspecialchars((string) $payment['display_total'], ENT_QUOTES, 'UTF-8') . '</strong>',
            '%coin%' => '<strong>' . htmlspecialchars((string) $payment['coin_label'], ENT_QUOTES, 'UTF-8') . '</strong>',
        ]);

        $this->context->smarty->assign([
            'pgc_payment' => $payment,
            'pgc_send_html' => $send,
            'pgc_message' => $this->statusMessage($payment),
            'pgc_status_url' => $this->context->link->getModuleLink($this->name, 'status', ['id_cart' => (int) $payment['id_cart'], 'key' => $payment['status_key']], true),
            'pgc_hosted_url' => $payment['mode'] === 'multi' ? $this->hostedUrl($payment['payment_token'], (bool) $payment['fees']) : '',
        ]);

        return $this->fetch('module:' . $this->name . '/views/templates/hook/payment_return.tpl');
    }

    /**
     * Crypto payment details on the back-office order page.
     */
    public function hookDisplayAdminOrderMainBottom($params)
    {
        $order = new Order(isset($params['id_order']) ? (int) $params['id_order'] : 0);
        $payment = Validate::isLoadedObject($order) && $order->module === $this->name ? $this->getPaymentByCart((int) $order->id_cart) : [];

        if (!$payment) {
            return '';
        }

        $this->context->smarty->assign(['pgc_payment' => $payment, 'pgc_txids' => $payment['txids'] !== '' ? explode(',', $payment['txids']) : []]);

        return $this->display(__FILE__, 'views/templates/admin/order_payment.tpl');
    }

    /**
     * Hosted checkout URL, on your custom checkout domain when one is set.
     *
     * @param string $token payment_token exactly as returned by PayGate (already URL-encoded)
     * @param bool $feesOn
     *
     * @return string
     */
    public function hostedUrl($token, $feesOn)
    {
        $domain = PaygateCryptoApi::normaliseHost((string) Configuration::get('PGC_MULTI_CHECKOUT_DOMAIN'), PaygateCryptoApi::DEFAULT_CHECKOUT_HOST);
        $url = 'https://' . $domain . '/crypto/hosted.php?payment_token=' . $token . '&add_fees=' . ($feesOn ? '1' : '0');

        foreach (['logo' => 'PGC_MULTI_LOGO', 'background' => 'PGC_MULTI_BACKGROUND', 'theme' => 'PGC_MULTI_THEME', 'button' => 'PGC_MULTI_BUTTON'] as $param => $key) {
            $value = trim((string) Configuration::get($key));

            if ($value !== '') {
                $url .= '&' . $param . '=' . urlencode($value);
            }
        }

        return $url;
    }
}
