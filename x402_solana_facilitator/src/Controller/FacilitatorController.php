<?php

namespace Drupal\x402_solana_facilitator\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Component\Serialization\Json;

class FacilitatorController extends ControllerBase {

  public function verify(Request $request) {
    $input = Json::decode($request->getContent());
    $payload = $input['payload'] ?? NULL;

    if (!$payload || empty($payload['tx'])) {
      return new JsonResponse(['error' => 'Invalid payload'], 400);
    }

    // TODO: inject a solana service!! 
    $tx = $this->getSolanaTransaction($payload['tx']);
    if (!$tx) {
      return new JsonResponse(['error' => 'Transaction not found'], 402);
    }

    // TODO make configuration
    $config = \Drupal::config('x402_solana_core.settings');
    $recipient = $config->get('wallet_address');
    $amount = $payload['amount'] ?? 0;

    if (!$this->verifyUsdcTransfer($tx, $recipient, $amount)) {
      return new JsonResponse(['error' => 'Invalid transfer'], 402);
    }

    // Generate signed proof
    $proof = [
      'tx' => $payload['tx'],
      'amount' => $amount,
      'timestamp' => time(),
      'nonce' => bin2hex(random_bytes(16)),
    ];
    $proof['signature'] = $this->signProof($proof);

    return new JsonResponse(['proof' => $proof]);
  }

  private function getSolanaTransaction($signature) {
    // TODO: move this to a configurable solana service client!!
    $client = \Drupal::httpClient();
    $rpc = 'https://api.devnet.solana.com'; // Make configurable
    $response = $client->post($rpc, [
      'json' => [
        'jsonrpc' => '2.0',
        'id' => 1,
        'method' => 'getTransaction',
        'params' => [$signature, 'jsonParsed']
      ]
    ]);
    $data = json_decode($response->getBody(), TRUE);
    return $data['result'] ?? NULL;
  }

  private function verifyUsdcTransfer($tx, $recipient, $amount) {
    if (!$tx['transaction']['message']['instructions']) return FALSE;
    foreach ($tx['transaction']['message']['instructions'] as $ix) {
      if ($ix['programId'] === 'TokenkegQfeZyiNwAJbNbGKL8b7u8o8N8r9bN9p9p9p9p') { // TODO make it configurable!
        $data = base64_decode($ix['parsed']['info']['data'] ?? '');
        // Simplified: check destination and amount
        if ($ix['parsed']['info']['destination'] === $recipient) {
          $paid = $ix['parsed']['info']['amount'] / 1_000_000; // USDC has 6 decimals
          return $paid >= $amount;
        }
      }
    }
    return FALSE;
  }

  private function signProof($proof) {
    // In production: use wallet private key
    return base64_encode(hash('sha256', json_encode($proof), TRUE));
  }
}