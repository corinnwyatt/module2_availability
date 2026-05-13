<?php

/**
 * @file
 * Contains \Drupal\jibc_api_migration\Plugin\migrate\process\GetCourseOfferings.
 */

namespace Drupal\jibc_api_migration\Plugin\migrate\process;

use Drupal\migrate\MigrateExecutableInterface;
use Drupal\migrate\ProcessPluginBase;
use Drupal\migrate\Row;
use Drupal\node\Entity\Node;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Process plugin to handle course offerings
 *
 * @MigrateProcessPlugin(
 *   id = "jibc_get_course_offerings"
 * )
 */
class GetCourseOfferings extends ProcessPluginBase {
  /**
   * {@inheritdoc}
   */
  public function transform($value, MigrateExecutableInterface $migrate_executable, Row $row, $destination_property) {
    // Check if we're getting an array of offerings or a single offering
    if (isset($value[0]) && is_array($value[0])) {
      // New structure - array of offerings
      return $this->processMultipleOfferings($value, $migrate_executable, $row);
    } else {
      // Old structure - single offering (keeping for backwards compatibility)
      return $this->processSingleOffering($value, $migrate_executable, $row);
    }
  }

  /**
   * Process multiple offerings (new API structure)
   */
  protected function processMultipleOfferings($offerings, MigrateExecutableInterface $migrate_executable, Row $row) {
    // Sort offerings by start date to fix date ordering issue
    usort($offerings, function($a, $b) {
      $date_a = strtotime($a['CourseSec_StartDate'] ?? '1900-01-01');
      $date_b = strtotime($b['CourseSec_StartDate'] ?? '1900-01-01');
      return $date_a - $date_b;
    });

    $paragraph_entities = [];

    foreach ($offerings as $value) {
      // Skip invalid offerings
      if (!is_array($value) || empty($value["CourseSec_ID"]) || $value["CourseSec_ID"] == "-1") {
        continue;
      }

      $offering = $this->processSingleOffering($value, $migrate_executable, $row);
      if ($offering) {
        $paragraph_entities[] = $offering;
      }
    }

    return $paragraph_entities;
  }

  /**
   * Process a single offering
   */
  protected function processSingleOffering($value, MigrateExecutableInterface $migrate_executable, Row $row) {
    // Skip if not array or invalid ID
    if (!is_array($value) || empty($value["CourseSec_ID"]) || $value["CourseSec_ID"] == "-1") {
      return NULL;
    }

    /** @var \Drupal\jibc_api_migration\CourseOfferingService $service */
    $service = \Drupal::service('jibc_api_migration.cos');

    // Process campus
    $camp_us = "";
    if (!empty($value["CourseSec_Location"])) {
      if ($campus = $service->getCampus($value["CourseSec_Location"])) {
        $camp_us = Node::load($campus->nid);
      } else {
        $camp_us = $value["CourseSec_Location"];
      }
    }

    $section_id = $value["CourseSec_ID"];
    $section_name = $value["CourseSec_Name"] ?? '';
    $term = $value["CourseSec_Term"] ?? '';

    // Try to load existing paragraph.
    // If the tracking record exists but the paragraph entity does not (e.g. orphaned
    // data after a rollback or manual deletion), fall through to create a new one.
    $c_o = $service->getCourseOffering_entity_id($section_id);
    $course_offering = NULL;

    if ($c_o && $c_o->entity_id) {
      $course_offering = Paragraph::load($c_o->entity_id);
    }

    if ($course_offering) {
      // Update existing offering
      $course_offering->set('field_course_id', $section_id)
        ->set('field_campus', $camp_us)
        ->set('field_campus_location', $value["CourseSec_Location"] ?? '')
        ->set('field_offering_name', $value["CourseSec_Title"] ?? '')
        ->set('field_offering_section_name', $section_name)
        ->set('field_professor', $value["CourseSec_Instructors"] ?? '')
        ->set('field_term', $term);

      // Handle dates safely
      if (!empty($value["CourseSec_StartDate"]) && !empty($value["CourseSec_EndDate"])) {
        $course_offering->set('field_dates', [
          'value' => $value["CourseSec_StartDate"],
          'end_value' => $value["CourseSec_EndDate"]
        ]);
      }

      // Handle meeting times safely
      if (!empty($value["CourseSectionMeetings"]) && is_array($value["CourseSectionMeetings"]) && !empty($value["CourseSectionMeetings"][0]["CourseSecMeeting_DaysTimes"])) {
        $course_offering->set('field_course_date_times', $value["CourseSectionMeetings"][0]["CourseSecMeeting_DaysTimes"]);
      }

      // Set all pricing fields
      $course_offering->set('field_domestic_tuition', $value["CourseSecPrice_DomPrice"] ?? '-')
        ->set('field_domestic_fee', $value["CourseSecPrice_DomFee"] ?? '-')
        ->set('field_domestic_fees_other', $value["CourseSecPrice_DomFee_Other"] ?? '-')
        ->set('field_international_tuition', $value["CourseSecPrice_IntPrice"] ?? '-')
        ->set('field_capacity_flag', $value["CourseSec_OverCapacity_Flag"] ?? '')
        ->set('field_credit_type_message', $value["CourseSec_Credit_Type_Message"] ?? '')
        ->set('field_international_fee', $value["CourseSecPrice_IntFee"] ?? '-')
        ->set('field_international_fees_other', $value["CourseSecPrice_IntFee_Other"] ?? '-')
        ->set('field_link', $value["CourseSec_Credit_Type"] ?? '')
        ->set('field_course_offering_type', $value["CourseSec_Type"] ?? '');

      try {
        $course_offering->save();
      } catch (\Exception $e) {
        \Drupal::logger('jibc_api_migration')->error('Failed to update offering @id: @error', [
          '@id' => $section_id,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }

    } else {
      // Create new offering.
      // This handles both: offering has never existed, AND tracking record exists
      // but the paragraph entity was deleted (orphaned data) - in both cases we
      // create a fresh paragraph rather than silently returning NULL.
      $course_offering_new = [
        'id' => NULL,
        'type' => 'course_offering',
        'field_course_id' => $section_id,
        'field_offering_section_name' => $section_name,
        'field_campus' => $camp_us,
        'field_campus_location' => $value["CourseSec_Location"] ?? '',
        'field_offering_name' => $value["CourseSec_Title"] ?? '',
        'field_professor' => $value["CourseSec_Instructors"] ?? '',
        'field_term' => $term,
      ];

      // Handle dates safely
      if (!empty($value["CourseSec_StartDate"]) && !empty($value["CourseSec_EndDate"])) {
        $course_offering_new['field_dates'] = [
          'value' => $value["CourseSec_StartDate"],
          'end_value' => $value["CourseSec_EndDate"]
        ];
      }

      // Handle meeting times safely
      if (!empty($value["CourseSectionMeetings"]) && is_array($value["CourseSectionMeetings"]) && !empty($value["CourseSectionMeetings"][0]["CourseSecMeeting_DaysTimes"])) {
        $course_offering_new['field_course_date_times'] = $value["CourseSectionMeetings"][0]["CourseSecMeeting_DaysTimes"];
      }

      // Set all pricing fields with defaults
      $course_offering_new['field_domestic_tuition'] = $value["CourseSecPrice_DomPrice"] ?? '-';
      $course_offering_new['field_domestic_fee'] = $value["CourseSecPrice_DomFee"] ?? '-';
      $course_offering_new['field_domestic_fees_other'] = $value["CourseSecPrice_DomFee_Other"] ?? '-';
      $course_offering_new['field_international_tuition'] = $value["CourseSecPrice_IntPrice"] ?? '-';
      $course_offering_new['field_capacity_flag'] = $value["CourseSec_OverCapacity_Flag"] ?? '';
      $course_offering_new['field_credit_type_message'] = $value["CourseSec_Credit_Type_Message"] ?? '';
      $course_offering_new['field_international_fee'] = $value["CourseSecPrice_IntFee"] ?? '-';
      $course_offering_new['field_international_fees_other'] = $value["CourseSecPrice_IntFee_Other"] ?? '-';
      $course_offering_new['field_link'] = $value["CourseSec_Credit_Type"] ?? '';
      $course_offering_new['field_course_offering_type'] = $value["CourseSec_Type"] ?? '';

      try {
        $course_offering = Paragraph::create($course_offering_new);
        $course_offering->save();
      } catch (\Exception $e) {
        \Drupal::logger('jibc_api_migration')->error('Failed to create offering @name: @error', [
          '@name' => $section_name,
          '@error' => $e->getMessage(),
        ]);
        return NULL;
      }
    }

    return $course_offering;
  }
}
