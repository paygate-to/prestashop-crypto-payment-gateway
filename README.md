# prestashop-crypto-payment-gateway
PrestaShop crypto payment gateway with instant payouts. No KYC crypto payment gateway module for PrestaShop, self hosted on your PrestaShop store.

* Accept cryptocurrencies instantly, unique receiving address for every order.
* QR code displayed on your own store's order page making payment easy for the customer, or one Multicoin option where the customer picks any coin on the hosted checkout page.
* Precise payment detection, our bot will notify your store after confirming the transaction, marking orders as paid. Every notification is signed by PayGate and verified by the module.
* Once the customer sends to the specified receiving address, crypto will be instantly forwarded to your specified wallet without any delay or holds.
* No KYC and without any registration. Both the merchant and the customer stay anonymous.
* You can control who will handle the blockchain payment fees: the customer or you the merchant.
* If the customer sends less than the order total, the rest can be sent to the same address and both payments are added up.
* If the customer made a mistake sending to the wrong token or sending through the wrong network our system will still forward the crypto to you automatically for all supported tokens across all chains.
* Works with your own dedicated API endpoint and custom checkout domain from the [white label guide](https://paygate.to/white-label-api-custom-domain-guide/).
* 1.5% flat rate fee + blockchain fees.
* You can accept payments worldwide without restrictions due to the decentralized nature of how cryptocurrencies work.
* Multi-currency support.
* Supported networks: BTC – BCH – Monero (XMR) - Zcash LTC – doge – ETH – TRC20 – ERC20 – BEP20 – Arbitrum – Polygon – AVAX-C – Optimism – Base – Solana [check the full list of supported coins and minimum order value per coin](https://paygate.to/crypto-payment-gateway-no-kyc-instant-payouts/#minimumorder).

# Install Guide

Requires PrestaShop 1.7.7 or newer.

* Download [cryptopayment_gateway.zip](https://github.com/paygate-to/prestashop-crypto-payment-gateway/raw/refs/heads/main/cryptopayment_gateway.zip)
* In your PrestaShop admin open Modules >> Module Manager, click Upload a module and select the zip file.
* Click Configure. Two payment options can be enabled on their own:
  * **Individual coins**: the customer picks a coin at checkout and pays with the QR code on your order confirmation page.
  * **Multicoin**: the customer picks any coin on the hosted multi-coin checkout page.
* Tick the coins you want to accept and insert your wallet address for each of them (Multicoin: one wallet per network), enable the option and save.
* Optional: paste your dedicated endpoint into **API Domain**, and your custom checkout domain into **Custom Domain** (Multicoin).

Full guide: [paygate.to/crypto-payment-gateway-no-kyc-instant-payouts](https://paygate.to/crypto-payment-gateway-no-kyc-instant-payouts/#prestashopdownload)
