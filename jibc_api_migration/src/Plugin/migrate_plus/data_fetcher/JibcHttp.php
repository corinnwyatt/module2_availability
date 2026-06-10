<?php

namespace Drupal\jibc_api_migration\Plugin\migrate_plus\data_fetcher;

use Drupal\migrate_plus\Plugin\migrate_plus\data_fetcher\Http;
use GuzzleHttp\Exception\RequestException;
use Psr\Http\Message\ResponseInterface;
use Drupal\Core\Site\Settings;

/**
 * Retrieve data over an HTTP connection for JIBC API with custom headers
 *
 * @DataFetcher(
 *   id = "jibc_http",
 *   title = @Translation("JIBC HTTP")
 * )
 */
class JibcHttp extends Http {

  /**
   * Get API configuration from secure settings
   */
  protected function getApiConfig() {
    // Get configuration from settings.php
    $settings_config = Settings::get('jibc_api', []);
    
    $config = [
      'token' => $settings_config['workato_auth_token'] ?? NULL,
      'base_url' => $settings_config['api_base_url'] ?? NULL,
      'timeout' => $settings_config['timeout'] ?? 60,
      'connect_timeout' => $settings_config['connect_timeout'] ?? 15,
    ];
    
    // Validate configuration
    if (empty($config['token'])) {
      throw new \Exception('No API token configured in settings.php');
    }
    if (empty($config['base_url'])) {
      throw new \Exception('No API base URL configured in settings.php');
    }
    
    return $config;
  }

  /**
   * Check if we should log (throttle to once per minute).
   */
  protected function shouldLog($key) {
    $state = \Drupal::state();
    $last_log = $state->get('jibc_api_migration.last_log.' . $key, 0);
    $now = time();
    
    // Only log once per 60 seconds for each key
    if (($now - $last_log) > 60) {
      $state->set('jibc_api_migration.last_log.' . $key, $now);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getResponse($url): ResponseInterface {
    try {
      // Get the API configuration
      $api_config = $this->getApiConfig();
      
      // Build the dynamic URL - always use /courses endpoint
      $url = rtrim($api_config['base_url'], '/') . '/courses';
      
      // Log only once per minute to avoid spamming the logs
      if ($this->shouldLog('fetch')) {
        \Drupal::logger('jibc_api_migration')->info('Fetching courses from API');
      }
      
      $headers = [
        'Accept' => 'application/json',
        'Content-Type' => 'application/json',
        'api-token' => $api_config['token'],
        'Api-Token' => $api_config['token'],
        'API-Token' => $api_config['token'],
      ];

      // Merge any additional headers from configuration (except tokens)
      if (!empty($this->configuration['headers']) && is_array($this->configuration['headers'])) {
        foreach ($this->configuration['headers'] as $key => $value) {
          // Skip any token headers from config (security)
          if (stripos($key, 'token') === FALSE && stripos($key, 'auth') === FALSE) {
            $headers[$key] = $value;
          }
        }
      }

      $options = [
        'headers' => $headers,
        'http_errors' => TRUE,
        'verify' => TRUE,
        'timeout' => $api_config['timeout'],
        'connect_timeout' => $api_config['connect_timeout'],
      ];

      // Make the request
      $response = $this->httpClient->get($url, $options);
      
      // DON'T read the body here - let the parent class handle it
      // Just validate the status code
      if ($response->getStatusCode() >= 400) {
        throw new \Exception('API returned error status: ' . $response->getStatusCode());
      }
      
      // Return the response as-is - the stream is still readable
      return $response;
    }
    catch (RequestException $e) {
      $error_msg = 'API request to ' . ($url ?? 'unknown') . ' failed: ' . $e->getMessage();
      if ($e->hasResponse()) {
        $status = $e->getResponse()->getStatusCode();
        $error_msg .= ' (HTTP ' . $status . ')';
        
        // Try to get error body for debugging
        try {
          $error_body = (string) $e->getResponse()->getBody();
          if (!empty($error_body)) {
            \Drupal::logger('jibc_api_migration')->error('API error body: @body', [
              '@body' => substr($error_body, 0, 500)
            ]);
          }
        } catch (\Exception $body_error) {
          // Ignore if we can't read the error body
        }
      }
      \Drupal::logger('jibc_api_migration')->error($error_msg);
      throw new \Exception($error_msg);
    }
    catch (\Exception $e) {
      \Drupal::logger('jibc_api_migration')->error('Unexpected error fetching API: @message', [
        '@message' => $e->getMessage()
      ]);
      throw $e;
    }
  }

  /**
   * {@inheritdoc}
   */
  public function getResponseContent($url): string {
    // Get the response (which still has a readable stream)
    $response = $this->getResponse($url);
    
    // NOW read the body - only once
    $body_contents = (string) $response->getBody();
    
    // Validate JSON structure
    $decoded = json_decode($body_contents, TRUE);
    if (json_last_error() !== JSON_ERROR_NONE) {
      throw new \Exception('Invalid JSON response: ' . json_last_error_msg());
    }
    
    // Validate structure
    if (!isset($decoded['array']) || !is_array($decoded['array'])) {
      \Drupal::logger('jibc_api_migration')->warning('API response missing "array" key. Structure: @keys', [
        '@keys' => implode(', ', array_keys($decoded ?? [])),
      ]);
    } else {
      // Log course count only once per minute
      if ($this->shouldLog('count')) {
        $course_count = count($decoded['array']);
        \Drupal::logger('jibc_api_migration')->info('API returned @count courses', [
          '@count' => $course_count
        ]);
      }
    }
    
    return $body_contents;
  }
}
  /**
   * Reset the logging flags (useful for testing or forced refresh).
   */
#  public static function resetLoggingFlags() {
#    self::$hasLoggedThisRequest = FALSE;
#    self::$hasLoggedContentThisRequest = FALSE;
 # }
#}
