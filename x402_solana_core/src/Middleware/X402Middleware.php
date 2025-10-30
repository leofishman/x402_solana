<?php

namespace Drupal\x402_solana_core\Middleware;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Messenger\MessengerInterface;

class X402Middleware implements HttpKernelInterface {
  protected $kernel;
  protected $config;
  protected $messenger;

  public function __construct(HttpKernelInterface $kernel, ConfigFactoryInterface $config, MessengerInterface $messenger) {
    $this->kernel = $kernel;
    $this->config = $config;
    $this->messenger = $messenger;
  }

  public function handle(Request $request, $type = self::MAIN_REQUEST, $catch = TRUE): Response {
    $path = $request->getPathInfo();

    // Skip admin, assets, etc.
    if (strpos($path, '/admin') === 0 || strpos($path, '/x402/verify') === 0) {
      return $this->kernel->handle($request, $type, $catch);
    }

    $protected = $this->isProtected($request);
    if ($protected && !$this->hasValidPayment($request)) {
      return $this->return402($request, $protected);
    }

    return $this->kernel->handle($request, $type, $catch);
  }

  protected function isProtected(Request $request) {
    $config = $this->config->get('x402_solana_core.settings');
    $enabled = $config->get('enabled_paths') ?? [];
    $path = $request->getPathInfo();
    foreach ($enabled as $pattern) {
      if (fnmatch($pattern, $path)) {
        return $config->get('default_price') ?? 0.01;
      }
    }
    return FALSE;
  }

  protected function hasValidPayment(Request $request) {
    if (!$request->headers->has('X402-Payment')) {
      return FALSE;
    }

    $payload = json_decode($request->headers->get('X402-Payment'), TRUE);
    if (!$payload) return FALSE;

    $client = \Drupal::httpClient();
    try {
      $response = $client->post($this->getFacilitatorUrl() . '/verify', [
        'json' => ['payload' => $payload],
      ]);
      return $response->getStatusCode() === 200;
    } catch (\Exception $e) {
      return FALSE;
    }
  }

  protected function return402(Request $request, $price) {
    $config = $this->config->get('x402_solana_core.settings');
    $intent = [
      'amount' => $price,
      'currency' => 'USDC',
      'network' => 'solana',
      'recipient' => $config->get('wallet_address'),
      'facilitator' => $this->getFacilitatorUrl(),
      'description' => 'Premium content access',
    ];

    return new Response(
      json_encode($intent),
      Response::HTTP_PAYMENT_REQUIRED,
      ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store']
    );
  }

  protected function getFacilitatorUrl() {
    $config = $this->config->get('x402_solana_core.settings');
    return rtrim($config->get('facilitator_url') ?: \Drupal::request()->getSchemeAndHttpHost(), '/') . '/x402';
  }
}