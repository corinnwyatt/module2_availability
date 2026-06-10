<?php

namespace Drupal\jibc_api_migration\Form;

use Drupal\Core\Form\ConfigFormBase;
use Symfony\Component\HttpFoundation\Request;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Site\Settings;

/**
 * Defines a form to configure JIBC API Migration module settings
 */
class APIMigrationSettingsForm extends ConfigFormBase {
  
  /**
   * {@inheritdoc}
   */
  public function getFormID() {
    return 'jibc_api_migration_admin_settings';
  }
  
  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'jibc_api_migration.settings',
    ];
  }
  
  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, Request $request = NULL) {
    $config = $this->config('jibc_api_migration.settings');
    
    // Check if token is set in settings.php
    $settings_config = Settings::get('jibc_api', []);
    $has_settings_token = !empty($settings_config['workato_auth_token']);

    // API Configuration Section
    $form['jibc_api_migration_api_options'] = [
      '#type' => 'details',
      '#title' => t('Workato API Configuration'),
      '#description' => $has_settings_token 
        ? t('<strong>✓ API token is configured in settings.php (secure)</strong>')
        : t('<strong>⚠ API token should be configured in settings.php for security</strong>'),
      '#open' => TRUE,
    ];

    // Only show token field if not in settings.php
    if (!$has_settings_token) {
      $form['jibc_api_migration_api_options']['jibc_api_migration_api_token'] = [
        '#type' => 'password',  // Changed to password field for security
        '#title' => $this->t('Workato API Token (Fallback)'),
        '#default_value' => $config->get('jibc_api_migration_api_token'),
        '#description' => t('⚠ This is less secure than settings.php. Only use for testing. Token will be hidden after saving.'),
      ];
      
      // Show instructions for proper setup
      $form['jibc_api_migration_api_options']['token_instructions'] = [
        '#markup' => '<div class="messages messages--warning">' . 
          '<h3>' . t('Recommended: Configure token in settings.php') . '</h3>' .
          '<pre>// In settings.php:
$settings[\'jibc_api\'][\'workato_auth_token\'] = \'your-token-here\';

// Or use environment variable:
$settings[\'jibc_api\'][\'workato_auth_token\'] = getenv(\'WORKATO_API_TOKEN\');</pre>' .
          '<p>' . t('For Pantheon, use Terminus Secrets:') . '</p>' .
          '<pre>terminus secret:set SITE.ENV WORKATO_API_TOKEN "your-token"</pre>' .
          '</div>',
      ];
    } else {
      $form['jibc_api_migration_api_options']['token_status'] = [
        '#markup' => '<div class="messages messages--status">' . 
          t('Token is securely configured in settings.php') . 
          '</div>',
      ];
    }

    // Refresh Configuration Section
    $form['jibc_api_migration_refresh_options'] = [
      '#type' => 'details',
      '#title' => t('Migration Schedule'),
      '#open' => TRUE,
    ];
    
    $freq_hours = [
      0 => t('Select'),
      10800 => t('3 hours'),
      21600 => t('6 hours'),
      32400 => t('9 hours'),
      43200 => t('12 hours'),
    ];

    $form['jibc_api_migration_refresh_options']['jibc_api_migration_refresh_frequency'] = [
      '#type' => 'select',
      '#title' => $this->t('Frequency of refresh'),
      '#default_value' => $config->get('jibc_api_migration_refresh_frequency') ?: 21600,
      '#options' => $freq_hours,
      '#description' => t('How often the migration should run via cron'),
      '#required' => TRUE,
    ];

    // Email Configuration Section
    $form['jibc_api_migration_email_options'] = [
      '#type' => 'details',
      '#title' => t('Email Notifications'),
      '#open' => TRUE,
    ];
    
    $form['jibc_api_migration_email_options']['jibc_api_migration_email_receipients'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Notification email address'),
      '#default_value' => $config->get('jibc_api_migration_email_receipients'),
      '#required' => TRUE,
      '#size' => 60,
      '#description' => t('Email address to receive migration status reports'),
    ];
    
    // Status information
    $form['status'] = [
      '#type' => 'details',
      '#title' => t('System Status'),
      '#open' => TRUE,
    ];
    
    // Show current configuration status
    $status_items = [];
    
    // Check token source
    if ($has_settings_token) {
      $status_items[] = t('✓ Token configured in settings.php (secure)');
    } elseif ($config->get('jibc_api_migration_api_token')) {
      $status_items[] = t('⚠ Token configured in database (less secure)');
    } else {
      $status_items[] = t('✗ No token configured');
    }
    
    // Check API endpoint
    $api_base = $settings_config['api_base_url'] ?? 'https://apim.workato.com/jibc/coursefetch-v1-1';
    $status_items[] = t('API Endpoint: @url', ['@url' => $api_base]);
    
    // Check last migration run
    $last_run = \Drupal::database()->query("
      SELECT MAX(timestamp) FROM {watchdog} 
      WHERE type = 'jibc_api_migration' 
      AND message LIKE '%importer ran%'
    ")->fetchField();
    
    if ($last_run) {
      $hours_ago = round((time() - $last_run) / 3600, 1);
      $status_items[] = t('Last migration: @hours hours ago', ['@hours' => $hours_ago]);
    } else {
      $status_items[] = t('No migration runs detected');
    }
    
    $form['status']['status_list'] = [
      '#theme' => 'item_list',
      '#items' => $status_items,
    ];
    
    return parent::buildForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('jibc_api_migration.settings');
    
    // Only save token if provided (won't override if using settings.php)
    if ($form_state->getValue('jibc_api_migration_api_token')) {
      $config->set('jibc_api_migration_api_token', $form_state->getValue('jibc_api_migration_api_token'));
    }
    
    $config
      ->set('jibc_api_migration_refresh_frequency', $form_state->getValue('jibc_api_migration_refresh_frequency'))
      ->set('jibc_api_migration_email_receipients', $form_state->getValue('jibc_api_migration_email_receipients'))
      ->save();
      
    parent::submitForm($form, $form_state);
  }
  
  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state) {
    $email = trim($form_state->getValue('jibc_api_migration_email_receipients'));
    
    if ($form_state->getValue('jibc_api_migration_refresh_frequency') <= 0) {
      $form_state->setErrorByName('jibc_api_migration_refresh_frequency', $this->t('Please select frequency of refresh'));
    }
    
    if (!empty($email) && !\Drupal::service('email.validator')->isValid($email)) {
      $form_state->setErrorByName('jibc_api_migration_email_receipients', $this->t('Please enter a valid email address'));
    }
  }
}