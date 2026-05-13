<?php

namespace Drupal\jibc_api_migration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;
use GuzzleHttp\Exception\RequestException;
use Drupal\Core\Site\Settings;

class JIBCCourseAvailability extends ControllerBase {

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
   * Gets course availability from Workato API
   */
  public function getCourse() {
    $client = \Drupal::httpClient();

    // Get course_id from query string
    $course_id = \Drupal::request()->query->get('course_id');

    if (empty($course_id)) {
      \Drupal::logger('jibc_api_migration')->warning('Course availability request missing course_id parameter');
      return new JsonResponse(['error' => 'Missing course_id parameter'], 400);
    }

    $course_id = trim($course_id);

    try {
      // Get secure API configuration from settings.php
      $api_config = $this->getApiConfig();
      
      // Construct the Workato API URL using the dynamic base URL
      $url = rtrim($api_config['base_url'], '/') . "/course_section_availability/" . urlencode($course_id);
      
      // Log the URL being called (only in non-production)
      $settings_config = Settings::get('jibc_api', []);
      if (empty($settings_config['log_errors_only'])) {
        \Drupal::logger('jibc_api_migration')->info('Fetching availability from: @url', ['@url' => $url]);
      }

      $request = $client->get($url, [
        'headers' => [
          'Content-Type' => 'application/json',
          'Accept' => 'application/json',
          'api-token' => $api_config['token'],
          'Api-Token' => $api_config['token'],
          'API-Token' => $api_config['token'],
        ],
        'timeout' => $api_config['timeout'],
        'connect_timeout' => $api_config['connect_timeout'],
        'http_errors' => false,
      ]);

      $status_code = $request->getStatusCode();
      $response_body = $request->getBody()->getContents();
      
      // Only log errors
      if ($status_code >= 400) {
        \Drupal::logger('jibc_api_migration')->error('API returned error status @status for course @course from @url', [
          '@status' => $status_code,
          '@course' => $course_id,
          '@url' => $url,
        ]);
        return new JsonResponse([
          'error' => 'API returned error status ' . $status_code,
          'course_id' => $course_id
        ], $status_code);
      }

      // Parse JSON response
      $response = json_decode($response_body, true);
      
      if (json_last_error() !== JSON_ERROR_NONE) {
        \Drupal::logger('jibc_api_migration')->error('Invalid JSON response for course @course: @error', [
          '@course' => $course_id,
          '@error' => json_last_error_msg()
        ]);
        return new JsonResponse(['error' => 'Invalid JSON response'], 500);
      }

      // Validate response structure
      if (!isset($response['array']) || !is_array($response['array'])) {
        \Drupal::logger('jibc_api_migration')->warning('Unexpected API response structure for course @course', [
          '@course' => $course_id
        ]);
        return new JsonResponse([
          'error' => 'Unexpected API response structure',
          'response' => $response
        ], 500);
      }

      return new JsonResponse($response);
    }
    catch (\Exception $e) {
      \Drupal::logger('jibc_api_migration')->error('Error fetching availability for course @course: @message', [
        '@course' => $course_id,
        '@message' => $e->getMessage()
      ]);
      
      return new JsonResponse([
        'error' => 'Unable to fetch course availability',
        'course_id' => $course_id,
        'message' => $e->getMessage()
      ], 500);
    }
  }
}