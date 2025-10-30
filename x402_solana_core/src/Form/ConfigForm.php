<?php

namespace Drupal\x402_solana_core\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class ConfigForm extends ConfigFormBase {
  protected function getEditableConfigNames() {
    return ['x402_solana_core.settings'];
  }

  public function getFormId() {
    return 'x402_solana_core_config';
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('x402_solana_core.settings');

    $form['wallet_address'] = [
      '#type' => 'textfield',
      '#title' => 'Solana USDC Wallet',
      '#default_value' => $config->get('wallet_address'),
      '#required' => TRUE,
    ];

    $form['facilitator_url'] = [
      '#type' => 'url',
      '#title' => 'Facilitator URL',
      '#default_value' => $config->get('facilitator_url') ?: \Drupal::request()->getSchemeAndHttpHost(),
      '#description' => 'Leave blank to use built-in facilitator.',
    ];

    $form['default_price'] = [
      '#type' => 'number',
      '#title' => 'Default Price (USDC)',
      '#step' => 0.001,
      '#default_value' => $config->get('default_price') ?: 0.01,
    ];

    $form['enabled_paths'] = [
      '#type' => 'textarea',
      '#title' => 'Protected Paths (one per line, fnmatch)',
      '#default_value' => implode("\n", $config->get('enabled_paths') ?: []),
      '#description' => 'e.g. /premium/*',
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('x402_solana_core.settings')
      ->set('wallet_address', $form_state->getValue('wallet_address'))
      ->set('facilitator_url', rtrim($form_state->getValue('facilitator_url'), '/'))
      ->set('default_price', $form_state->getValue('default_price'))
      ->set('enabled_paths', array_filter(preg_split('/\r\n|\r|\n/', $form_state->getValue('enabled_paths'))))
      ->save();

    parent::submitForm($form, $form_state);
  }
}