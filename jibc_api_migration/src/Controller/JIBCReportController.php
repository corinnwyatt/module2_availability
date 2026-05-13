<?php
/**
 * @file
 * Contains \Drupal\jibc_api_migration\Controller\JIBCReportController.
 */
namespace Drupal\jibc_api_migration\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Database\Database;
use Drupal\dblog\Controller\DbLogController;
use Drupal\Component\Utility\Xss;
use Drupal\Component\Utility\Unicode;
use Drupal\Component\Utility\Html;
use Drupal\Core\Url;
use Drupal\Core\Datetime\DateFormatter;
use Drupal\user\Entity\User;
/**
 * Controller for JIBC API Integration Audit Report
 */

class JIBCReportController extends ControllerBase {

    /**
     * Gets reports specific to course refresh
     *
     * @return array
     */
    protected function load() {
        $select = Database::getConnection()->select('jibc_api_migration', 'r');
        // // Join the users table, so we can get the entry creator's username.
        // $select->join('users_field_data', 'u', 'r.uid = u.uid');
        // // Join the node table, so we can get the event's name.
        // $select->join('node_field_data', 'n', 'r.nid = n.nid');
        // // Select these specific fields for the output.
        // $select->addField('u', 'name', 'username');
        // $select->addField('n', 'title');
        // $select->addField('r', 'mail');
        $entries = $select->execute()->fetchAll(\PDO::FETCH_ASSOC);
        return $entries;
    }

    /**
     * The user storage.
     *
     * @var \Drupal\Core\UserStorageInterface
     */
    protected $userStorage;

     /**
     * The date formatter service.
     *
     * @var \Drupal\Core\Datetime\DateFormatterInterface
     */
    protected $dateFormatter;

    /**
     * Creates the report page
     *
     * @return array
     * Render array for report output.
     */
    public function report() {
        // $content = array();
        // $content['message'] = array(
        //     '#markup' => $this->t('Below is a list of all refreshes done till date'),
        // );
        // $headers = array(
        //     t('Name'),
        //     t('Event'),
        //     t('Email'),
        // );
        // $rows = array();
        // foreach ($entries = $this->load() as $entry) {
        //     // Sanitize each entry.
        //     $rows[] = array_map('Drupal\Component\Utility\SafeMarkup::checkPlain', $entry);
        // }
        // $content['table'] = array(
        //     '#type' => 'table',
        //     //'#header' => $headers,
        //     '#rows' => $rows,
        //     '#empty' => t('No entries available.'),
        // );
        // //Don't cache this page.
        // $content['#cahe']['max-age'] = 0;
        // return $content;


        //$filter = $this->buildFilterQuery();
        //$logHandler = new DbLogController('','','','');
        $rows = [];

        $classes = DbLogController::getLogLevelClassMap();
        $this->userStorage = $this->entityTypeManager()->getStorage('user');
        //DateFormatterInterface $dateFormatter;
        //DateFormatterInterface $date_formatter,
        //$this->dateFormatter = $new_formatter;

        //$this->moduleHandler->loadInclude('dblog', 'admin.inc');

        //$build['dblog_filter_form'] = $this->formBuilder->getForm('Drupal\dblog\Form\DblogFilterForm');

        $header = [
        // Icon column.
        '',
        [
            'data' => $this->t('Type'),
            'field' => 'w.type',
            'class' => [RESPONSIVE_PRIORITY_MEDIUM],
        ],
        [
            'data' => $this->t('Date'),
            'field' => 'w.wid',
            'sort' => 'desc',
            'class' => [RESPONSIVE_PRIORITY_LOW],
        ],
        $this->t('Message'),
        [
            'data' => $this->t('User'),
            'field' => 'ufd.name',
            'class' => [RESPONSIVE_PRIORITY_MEDIUM],
        ],
        [
            'data' => $this->t('Operations'),
            'class' => [RESPONSIVE_PRIORITY_LOW],
        ],
        ];

        $query = Database::getConnection()->select('watchdog', 'w')
        ->extend('\Drupal\Core\Database\Query\PagerSelectExtender')
        ->extend('\Drupal\Core\Database\Query\TableSortExtender');
        $query->fields('w', [
        'wid',
        'uid',
        'severity',
        'type',
        'timestamp',
        'message',
        'variables',
        'link',
        ]);
        $query->leftJoin('users_field_data', 'ufd', 'w.uid = ufd.uid');
        $orGrp = $query->orConditionGroup()
            ->condition('w.type', 'Course Refresh')
            ->condition('w.type', 'migrate');
        $query->condition($orGrp);
        $result = $query
        ->limit(50)
        ->orderByHeader($header)
        ->execute();

        foreach ($result as $dblog) {
        $message = $this->formatMessage($dblog);
        if ($message && isset($dblog->wid)) {
            $title = Unicode::truncate(Html::decodeEntities(strip_tags($message)), 256, TRUE, TRUE);
            $log_text = Unicode::truncate($title, 56, TRUE, TRUE);
            // The link generator will escape any unsafe HTML entities in the final
            // text.
            $message = \Drupal\Core\Link::fromTextAndUrl($log_text, new Url('dblog.event', ['event_id' => $dblog->wid], [
            'attributes' => [
                // Provide a title for the link for useful hover hints. The
                // Attribute object will escape any unsafe HTML entities in the
                // final text.
                'title' => $title,
            ],
            ]))->toString();
        }
        $username = [
            '#theme' => 'username',
            '#account' => $this->userStorage->load($dblog->uid),
        ];
        $rows[] = [
            'data' => [
            // Cells.
            ['class' => ['icon']],
            $this->t('Course Refresh'),
            //$this->t($dblog->type),
            \Drupal::service('date.formatter')->format($dblog->timestamp, 'short'),
            //$this->t($dblog->timestamp),
            $message,
            ['data' => $username],
            ['data' => ['#markup' => $dblog->link]],
            ],
            // Attributes for table row.
            'class' => [Html::getClass('dblog-' . $dblog->type), $classes[$dblog->severity]],
        ];
        }

        $build['dblog_table'] = [
        '#type' => 'table',
        '#header' => $header,
        '#rows' => $rows,
        '#attributes' => ['id' => 'admin-dblog', 'class' => ['admin-dblog']],
        '#empty' => $this->t('No log messages available.'),
        '#attached' => [
            'library' => ['dblog/drupal.dblog'],
        ],
        ];
        $build['dblog_pager'] = ['#type' => 'pager'];

        return $build;

    }
    /**
     * Formats a database log message.
     *
     * @param object $row
     *   The record from the watchdog table. The object properties are: wid, uid,
     *   severity, type, timestamp, message, variables, link, name.
     *
     * @return string|\Drupal\Core\StringTranslation\TranslatableMarkup|false
     *   The formatted log message or FALSE if the message or variables properties
     *   are not set.
     */
    public function formatMessage($row) {
        // Check for required properties.
        if (isset($row->message, $row->variables)) {
        $variables = @unserialize($row->variables);
        // Messages without variables or user specified text.
        if ($variables === NULL) {
            $message = Xss::filterAdmin($row->message);
        }
        elseif (!is_array($variables)) {
            $message = $this->t('Log data is corrupted and cannot be unserialized: @message', ['@message' => Xss::filterAdmin($row->message)]);
        }
        // Message to translate with injected variables.
        else {
            $message = $this->t(Xss::filterAdmin($row->message), $variables);
        }
        }
        else {
        $message = FALSE;
        }
        return $message;
  }

}