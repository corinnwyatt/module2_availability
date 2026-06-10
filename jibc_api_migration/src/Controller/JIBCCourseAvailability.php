<?php

namespace Drupal\jibc_api_migration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Course availability endpoint.
 *
 * No longer hits Workato per-request. Availability is refreshed in bulk
 * by CourseAvailabilityService::refreshAvailabilityCache(), invoked
 * hourly from hook_cron().
 *
 * On cache miss this falls back to the field_capacity_flag values
 * written by the regular migration (GetCourseOfferings.php). The
 * fallback is a local database read only - under no path does this
 * endpoint call Workato.
 *
 * Response shape is unchanged from the previous implementation so the
 * existing course-availability-check.js continues to work without edits:
 *   { array: [ { Course_ID, CourseSections: [ { CourseSec_ID, CourseSec_OverCapacity_Flag } ] } ] }
 */
class JIBCCourseAvailability extends ControllerBase {

  /**
   * Gets course availability from the local cache (or paragraph fallback).
   */
  public function getCourse() {
    $course_id = \Drupal::request()->query->get('course_id');

    if (empty($course_id)) {
      \Drupal::logger('jibc_api_migration')
        ->warning('Course availability request missing course_id parameter');
      return new JsonResponse(['error' => 'Missing course_id parameter'], 400);
    }

    $course_id = trim($course_id);

    /** @var \Drupal\jibc_api_migration\CourseAvailabilityService $service */
    $service = \Drupal::service('jibc_api_migration.availability');

    // Primary path: read from the hourly-refreshed cache.
    $sections = $service->getCourseAvailability($course_id);
    $source = 'cache';

    // Cache miss: fall back to whatever the last migration wrote to
    // field_capacity_flag. This still reflects availability data, just
    // from up to 6 hours ago. Critically, it does NOT call Workato.
    if ($sections === NULL) {
      $sections = $service->getCourseAvailabilityFromParagraphs($course_id);
      $source = 'paragraph_fallback';
      \Drupal::logger('jibc_api_migration')->info(
        'Availability cache miss for @course; served from paragraph field',
        ['@course' => $course_id]
      );
    }

    // Build the response in the shape the existing JS expects. Empty
    // array is fine - the JS bails early on json.array.length == 0.
    $response = [
      'array' => empty($sections) ? [] : [
        [
          'Course_ID' => $course_id,
          'CourseSections' => $sections,
        ],
      ],
      'source' => $source,
    ];

    return new JsonResponse($response);
  }

}
