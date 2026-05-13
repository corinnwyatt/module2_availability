<?php
/**
 * @file
 * Contains \Drupal\jibc_api_migration\EmailService
 */

namespace Drupal\jibc_api_migration;

/**
 * Class Email Service.
 * Defines a service to handle unpublishing of deleted courses from JIBC source JSON API data
 */
class EmailService {

  /**
   * Sends course refresh email notification to JIBC team.
   *
   * @param string $message
   *   The migration name plugin.
   *
   */
  public function sendEmail($message, $key) {
    $mailManager = \Drupal::service('plugin.manager.mail');
    $module = 'jibc_api_migration';
    //$key = 'node_insert'; // Replace with Your key
    $to = \Drupal::configFactory()
    ->getEditable('jibc_api_migration.settings')
    ->get('jibc_api_migration_email_receipients');
    $params['message'] = $message;
    $langcode = \Drupal::currentUser()->getPreferredLangcode();
    $send = TRUE;

    $result = $mailManager->mail($module, $key, $to, $langcode, $params, NULL, $send);
    if ($result['result'] != true) {
      $message = t('There was a problem sending your email notification to @email.', array('@email' => $to));
      //drupal_set_message($message, 'error');
      \Drupal::logger('Course Refresh')->error($message);
      return;
    }

    $message = t('An email notification has been sent to @email ', array('@email' => $to));
    //drupal_set_message($message);
    \Drupal::logger('Course Refresh')->notice($message);

  }
}