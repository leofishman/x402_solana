<?php

namespace Drupal\x402_solana_core\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use JosephOpanel\SolanaSDK\SolanaRPC;
use JosephOpanel\SolanaSDK\Endpoints\JsonRPC\Account;
use JosephOpanel\SolanaSDK\Endpoints\JsonRPC\Transaction;
use StephenHill\Base58;
use Drupal\Core\StringTranslation\StringTranslationTrait;

/**
 * A wrapper for the Solana PHP SDK, configured via Drupal services.
 */
class SolanaClient {
  use StringTranslationTrait;

  /**
   * The Solana RPC endpoint.
   *
   * @var string
   */
  protected string $endpoint;

  /**
   * The last converted SOL amount.
   *
   * @var float|null
   */
  protected ?float $lastConvertedAmount = NULL;


  /**
   * Constructs a new SolanaClient object.
   *
   * @param \Drupal\Core\Config\ConfigFactoryInterface $configFactory
   *   The config factory.
   */
  public function __construct(
    private readonly ConfigFactoryInterface $configFactory,
  ) {}

 public function getEndpoint(): string {
    $config = $this->configFactory->get('x402_solana_core.settings');
    $default_endpoint_key = $config->get('default_endpoint') ?? 'mainnet';
    $endpoints = $config->get('endpoints') ?? [];
    
    // Get the URL for the default endpoint.
    if (isset($endpoints[$default_endpoint_key]['url'])) {
      return (string) $endpoints[$default_endpoint_key]['url'];
    }
    
    // Fallback to the first enabled endpoint if default is not available.
    foreach ($endpoints as $endpoint) {
      if (!empty($endpoint['enabled']) && !empty($endpoint['url'])) {
        return (string) $endpoint['url'];
      }
    }
    
    // Final fallback to mainnet if nothing else is available.
    return 'https://api.mainnet-beta.solana.com';
  }

  protected function getTimeout(): int {
    return (int) ($this->configFactory->get('x402_solana_core.settings')->get('request_timeout') ?? 5);
  }

  /**
   * Get the balance of a Solana account.
   *
   * @param string $pubkey
   *   The public key of the account.
   * @param string|null $endpoint
   *   Optional. The RPC endpoint URL to use. If not provided, uses the default endpoint.
   *
   * @return array|null The balance in lamports, or null on error.
   */
  public function getBalance(string $pubkey, ?string $endpoint = NULL): ?array {
    $endpoint = $endpoint ?? $this->getEndpoint();
    $rpc = new SolanaRPC($endpoint);
    $account = new Account($rpc);    
    // Get the balance of an account
    $balance = $account->getBalance($pubkey);
    return $balance;
  }

  // --- START OF NEW METHODS FOR SOLANA PAY ---

  
  /**
   * Builds the Solana Pay payment request URL.
   *
   * @param string $recipient
   *   The recipient address.
   * @param float $amount
   *   The amount to pay.
   * @param string $spl_token
   *   The SPL token address (optional, omit for native SOL).
   * @param string $reference
   *   The reference ID.
   * @param string $label
   *   The label.
   * @param string $message
   *   The message.
   *
   * @return string
   *   The Solana Pay URL.
   */
  public function buildPaymentRequestUrl(string $recipient, float $amount, string $spl_token, string $reference, string $label, string $message): string {
    $amount_str = rtrim(rtrim(number_format($amount, 9, '.', ''), '0'), '.');
    
    $params = [
      'amount' => $amount_str,
    ];

    if (!empty($reference)) {
      $params['reference'] = $reference;
    }

    if (!empty($label)) {
      $params['label'] = $label;
    }

    if (!empty($message)) {
      $params['message'] = $message;
    }

    if (!empty($spl_token)) {
      $params['spl-token'] = $spl_token;
    }

    $cluster = $this->getClusterName();
    if ($cluster) {
      $params['cluster'] = $cluster;
    }

    $url_params = http_build_query($params);
    $full_url = "solana:" . $recipient . "?" . $url_params;
    
    \Drupal::logger('solana_pay')->info('Generated Solana Pay URL: @url', ['@url' => $full_url]);
    
    return $full_url;
  }

  /**
   * Gets the cluster name for Solana Pay URLs based on the configured endpoint.
   *
   * @return string|null
   *   The cluster name (mainnet-beta, devnet, testnet) or null for custom endpoints.
   */
  protected function getClusterName(): ?string {
    $config = $this->configFactory->get('x402_solana_core.settings');
    $default_endpoint_key = $config->get('default_endpoint') ?? 'mainnet';

    $cluster_map = [
      'mainnet' => 'mainnet-beta',
      'devnet' => 'devnet',
      'testnet' => 'testnet',
    ];

    return $cluster_map[$default_endpoint_key] ?? NULL;
  }

  /**
   * Converts fiat currency to SOL using CoinGecko API.
   *
   * @param float $amount
   *   The amount in fiat currency.
   * @param string $currency_code
   *   The currency code (e.g., "USD", "EUR").
   *
   * @return float|null
   *   The amount in SOL, or null on error.
   */
  protected function convertToSol(float $amount, string $currency_code): ?float {
    try {
      $currency_lower = strtolower($currency_code);
      $url = "https://api.coingecko.com/api/v3/simple/price?ids=solana&vs_currencies={$currency_lower}";
      
      $context = stream_context_create([
        'http' => [
          'timeout' => 5,
          'header' => "Accept: application/json\r\n",
        ],
      ]);
      
      $response = @file_get_contents($url, false, $context);
      
      if ($response === FALSE) {
        \Drupal::logger('x402_solana_core')->error('Failed to fetch SOL exchange rate from CoinGecko');
        return NULL;
      }
      
      $data = json_decode($response, TRUE);
      
      if (isset($data['solana'][$currency_lower])) {
        $sol_price = (float) $data['solana'][$currency_lower];
        return $amount / $sol_price;
      }
      
      \Drupal::logger('x402_solana_core')->error('Currency @currency not found in CoinGecko response', ['@currency' => $currency_code]);
      return NULL;
    }
    catch (\Exception $e) {
      \Drupal::logger('x402_solana_core')->error('Error converting currency: @message', ['@message' => $e->getMessage()]);
      return NULL;
    }
  }

  /**
   * Gets the last converted SOL amount.
   *
   * @return float|null
   *   The last converted SOL amount.
   */
  public function getLastConvertedAmount(): ?float {
    return $this->lastConvertedAmount;
  }

    /**
   * Generates a Solana Pay payment request URL.
   *
   * @param float $amount
   * The amount in fiat currency.
   * @param string $currency_code
   * The currency code (e.g., "USD", "EUR").
   * @param string $label
   * The label for the payment (e.g., "My Online Store").
   * @param string $message
   * A message for the payment (e.g., "Order #12345").
   * @param string &$reference_key
   * A variable passed by reference to store the generated reference public key.
   *
   * @return string|null
   * The generated solana: URL, or null if the merchant address is not configured.
   */
  public function generatePaymentRequest(float $amount, string $currency_code, string $label, string $message, ?string &$reference_key): ?string
  {
    $config = $this->configFactory->get('x402_solana_core.settings');
    $recipient = $config->get('merchant_wallet_address');

    if (empty($recipient)) {
      return NULL; // We cannot generate a request without a recipient.
    }

    if (!extension_loaded('sodium')) {
      \Drupal::logger('x402_solana_core')->error('The Sodium extension is not enabled. Please enable it to generate Solana Pay QR codes.');
      return NULL;
    }

    // Convert fiat currency to SOL
    $sol_amount = $this->convertToSol($amount, $currency_code);
    if ($sol_amount === NULL) {
      \Drupal::logger('x402_solana_core')->error('Failed to convert @amount @currency to SOL', [
        '@amount' => $amount,
        '@currency' => $currency_code,
      ]);
      return NULL;
    }

    // Store the converted amount for display purposes
    $this->lastConvertedAmount = $sol_amount;

    // CRITICAL: Each payment request MUST have a unique reference
    // to be able to track it on the blockchain without ambiguity.
    // We generate a new random keypair to use as the reference.
    $randomBytes = random_bytes(32);
    $keypair = \sodium_crypto_sign_seed_keypair($randomBytes);
    $publicKey = \sodium_crypto_sign_publickey($keypair);
    $base58 = new Base58();
    $reference_key = $base58->encode($publicKey);


    return $this->buildPaymentRequestUrl($recipient, $sol_amount, '', $reference_key, $label, $message);
  }

  /**
   * Verifies if a Solana Pay payment has been confirmed on the blockchain.
   *
   * @param string $reference_key
   * The public key used as a reference for the transaction.
   * @param float $expected_amount
   * The expected amount in SOL.
   *
   * @return bool
   * TRUE if the payment is fully confirmed and valid, FALSE otherwise.
   */
  public function verifyPayment(string $reference_key, float $expected_amount): bool
  {
    $config = $this->configFactory->get('x402_solana_core.settings');
    $merchant_wallet = $config->get('merchant_wallet_address');
    $endpoint = $this->getEndpoint();

    try {
      $rpc = new SolanaRPC($endpoint);
      $transaction_client = new Transaction($rpc);

      // 1. Find transaction signatures for the reference key.
      // The reference key is included in the transaction's keys,
      // allowing us to find it with getSignaturesForAddress.
      $signatures_response = $transaction_client->getSignaturesForAddress($reference_key, ['limit' => 1]);

      if (empty($signatures_response)) {
        return FALSE; // No transaction found for this reference.
      }

      $signature = $signatures_response[0]['signature'];

      // 2. Get the full transaction details using the signature.
      $tx_details = $transaction_client->getTransaction($signature, ['encoding' => 'jsonParsed', 'maxSupportedTransactionVersion' => 0]);
      
      if (empty($tx_details) || !empty($tx_details['meta']['err'])) {
        return FALSE; // Transaction not found or has failed.
      }

      // 3. Analyze the transaction for maximum security.
      // We look for a system program transfer instruction.
      $lamports_expected = $expected_amount * 1_000_000_000;
      $transfer_found_and_valid = false;

      foreach ($tx_details['transaction']['message']['instructions'] as $instruction) {
        if ($instruction['programId'] === '11111111111111111111111111111111') { // System Program
          if ($instruction['parsed']['type'] === 'transfer') {
            $info = $instruction['parsed']['info'];
            // We check if the destination and amount are correct.
            if ($info['destination'] === $merchant_wallet && $info['lamports'] == $lamports_expected) {
              $transfer_found_and_valid = true;
              break; // Valid instruction found, breaking the loop.
            }
          }
        }
      }

      return $transfer_found_and_valid;
    }
    catch (\Exception $e) {
      // Log the error if necessary, but return false on any exception.
      return FALSE;
    }
  }
}
