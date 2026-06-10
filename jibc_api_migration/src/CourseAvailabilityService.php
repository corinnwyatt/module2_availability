<?php

/**
 * @file
 * Contains \Drupal\jibc_api_migration\CourseAvailabilityService.
 */

namespace Drupal\jibc_api_migration;

use Drupal\Core\Database\Database;
use Drupal\Core\Site\Settings;
use GuzzleHttp\Exception\RequestException;

/**
 * Service to fetch and cache course availability data in bulk.
 *
 * Replaces the per-pageview Workato call (which was burning one task per
 * course page view) with a single hourly bulk fetch. The /course-availability
 * endpoint reads from this cache instead of hitting the API directly, so
 * the request path never consumes a Workato task.
 *
 * On cache miss (post-deploy, post-cache-clear, cron paused, etc.) the
 * fallback returns the field_capacity_flag values written by the regular
 * migration via GetCourseOfferings.php. This guarantees we NEVER call
 * Workato from the request path under any circumstance.
 */
class CourseAvailabilityService {

  /**
   * Cache ID for the availability map.
   *
   * Versioned so a schema change in the cached structure won't poison
   * existing cached data after a deploy - bump to v2 if the shape changes.
   */
  const CACHE_ID = 'jibc_api_migration:availability_map:v1';

  /**
   * Cache lifetime in seconds.
   *
   * Set to 2 hours so a delayed or briefly missed cron run still serves
   * cached data instead of falling back. If cron stops entirely for >2h,
   * we degrade gracefully to the paragraph field values.
   */
  const CACHE_LIFETIME = 7200;

  /**
   * State key for the last successful refresh timestamp.
   */
  const LAST_REFRESH_STATE_KEY = 'jibc_api_migration.availability_last_refresh';

  /**
   * Fetch /courses from Workato and rebuild the availability cache.
   *
   * Stores a map keyed by Course_ID, each value an array of section records
   * with CourseSec_ID and CourseSec_OverCapacity_Flag. This is the shape
   * the JS expects to receive (minus the outer envelope), so the endpoint
   * can serve it back with minimal reshaping.
   *
   * @return bool
   *   TRUE on success, FALSE on failure. Failures leave the existing
   *   cache entry untouched so we keep serving the last known good data.
   */
  public function refreshAvailabilityCache() {
    $logger = \Drupal::logger('jibc_api_migration');

    $settings_config = Settings::get('jibc_api', []);
    $token = $settings_config['workato_auth_token'] ?? NULL;
    $base_url = $settings_config['api_base_url'] ?? NULL;

    if (empty($token) || empty($base_url)) {
      $logger->error('Availability refresh aborted: Workato API not configured in settings.php.');
      return FALSE;
    }

    $url = rtrim($base_url, '/') . '/courses';

    try {
      $client = \Drupal::httpClient();
      $response = $client->get($url, [
        'headers' => [
          'Accept' => 'application/json',
          'Content-Type' => 'application/json',
          'api-token' => $token,
          'Api-Token' => $token,
          'API-Token' => $token,
        ],
        'timeout' => $settings_config['timeout'] ?? 60,
        'connect_timeout' => $settings_config['connect_timeout'] ?? 15,
        'http_errors' => FALSE,
      ]);

      $status = $response->getStatusCode();
      if ($status >= 400) {
        $logger->error('Availability refresh: API returned HTTP @code', ['@code' => $status]);
        return FALSE;
      }

      $body = (string) $response->getBody();
      $data = json_decode($body, TRUE);

      if (json_last_error() !== JSON_ERROR_NONE) {
        $logger->error('Availability refresh: invalid JSON: @err', [
          '@err' => json_last_error_msg(),
        ]);
        return FALSE;
      }

      if (!isset($data['array']) || !is_array($data['array'])) {
        $logger->error('Availability refresh: response missing "array" key.');
        return FALSE;
      }

      // Build the lightweight {Course_ID => [sections]} map. We deliberately
      // strip out every field except what the JS actually consumes, so the
      // cache entry stays small even with hundreds of courses.
      $map = [];
      $section_count = 0;

      foreach ($data['array'] as $course) {
        if (empty($course['Course_ID']) || empty($course['CourseSections'])) {
          continue;
        }
        $course_id = $course['Course_ID'];
        $sections = [];
        foreach ($course['CourseSections'] as $section) {
          // Mirror the same skip logic used by GetCourseOfferings.php:
          // CourseSec_ID of "-1" is the sentinel for "no real section".
          if (empty($section['CourseSec_ID']) || $section['CourseSec_ID'] == '-1') {
            continue;
          }
          $sections[] = [
            'CourseSec_ID' => $section['CourseSec_ID'],
            'CourseSec_OverCapacity_Flag' => $section['CourseSec_OverCapacity_Flag'] ?? '',
          ];
          $section_count++;
        }
        if (!empty($sections)) {
          $map[$course_id] = $sections;
        }
      }

      \Drupal::cache()->set(
        self::CACHE_ID,
        $map,
        time() + self::CACHE_LIFETIME
      );

      \Drupal::state()->set(self::LAST_REFRESH_STATE_KEY, time());

      $logger->notice('Availability cache refreshed: @courses courses, @sections sections', [
        '@courses' => count($map),
        '@sections' => $section_count,
      ]);

      return TRUE;
    }
    catch (RequestException $e) {
      $logger->error('Availability refresh request failed: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
    catch (\Exception $e) {
      $logger->error('Availability refresh error: @msg', ['@msg' => $e->getMessage()]);
      return FALSE;
    }
  }

  /**
   * Get availability for a single course from the cache.
   *
   * @param string $course_id
   *   The Course_ID (e.g., "BLAW-1000").
   *
   * @return array|null
   *   Array of section records, or NULL on cache miss (caller should
   *   then try the paragraph fallback).
   */
  public function getCourseAvailability($course_id) {
    $cached = \Drupal::cache()->get(self::CACHE_ID);
    if (!$cached || empty($cached->data)) {
      return NULL;
    }
    return $cached->data[$course_id] ?? NULL;
  }

  /**
   * Fallback: read availability from paragraph field_capacity_flag.
   *
   * Used when the cache is empty (post-deploy, post-cache-clear). Returns
   * whatever the last successful migration captured. This DOES NOT call
   * Workato - it's a local database read only, which is the whole point.
   *
   * Matches the data path written by GetCourseOfferings.php:
   *   course node -> field_course_offerings -> course_offering paragraph
   *   -> field_course_id (= CourseSec_ID), field_capacity_flag.
   *
   * @param string $course_id
   *   The Course_ID.
   *
   * @return array
   *   Array of section records (possibly empty).
   */
  public function getCourseAvailabilityFromParagraphs($course_id) {
    $db = Database::getConnection();

    $query = $db->select('node__field_course_id', 'ncid');
    $query->join(
      'node__field_course_offerings',
      'nfco',
      'nfco.entity_id = ncid.entity_id'
    );
    $query->join(
      'paragraph__field_course_id',
      'pfcid',
      'pfcid.entity_id = nfco.field_course_offerings_target_id'
    );
    $query->leftJoin(
      'paragraph__field_capacity_flag',
      'pfcap',
      'pfcap.entity_id = nfco.field_course_offerings_target_id'
    );
    $query->fields('pfcid', ['field_course_id_value']);
    $query->fields('pfcap', ['field_capacity_flag_value']);
    $query->condition('ncid.field_course_id_value', $course_id);

    $results = $query->execute()->fetchAll();

    $sections = [];
    foreach ($results as $row) {
      $sections[] = [
        'CourseSec_ID' => $row->field_course_id_value,
        'CourseSec_OverCapacity_Flag' => $row->field_capacity_flag_value ?? '',
      ];
    }
    return $sections;
  }

  /**
   * Get when the cache was last successfully refreshed.
   *
   * @return int
   *   Unix timestamp, or 0 if never.
   */
  public function getLastRefreshTime() {
    return \Drupal::state()->get(self::LAST_REFRESH_STATE_KEY, 0);
  }

}
