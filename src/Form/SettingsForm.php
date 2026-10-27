<?php

declare(strict_types=1);

namespace Drupal\tracker_monitoring\Form;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\TypedConfigManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Logger\RfcLogLevel;
use Drupal\tracker_monitoring\Config\SyslogFormatOverride;
use Drupal\tracker_monitoring\Key;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Settings for what the site sends to Tracker.
 */
final class SettingsForm extends ConfigFormBase {

  public function __construct(
    ConfigFactoryInterface $config_factory,
    TypedConfigManagerInterface $typed_config_manager,
    private readonly ModuleHandlerInterface $moduleHandler,
  ) {
    parent::__construct($config_factory, $typed_config_manager);
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): static {
    return new static(
      $container->get('config.factory'),
      $container->get('config.typed'),
      $container->get('module_handler'),
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'tracker_monitoring_settings';
  }

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames(): array {
    return ['tracker_monitoring.settings'];
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $config = $this->config('tracker_monitoring.settings');

    $form['connection'] = [
      '#type' => 'details',
      '#title' => $this->t('Connection'),
      '#open' => TRUE,
    ];
    $form['connection']['endpoint'] = [
      '#type' => 'url',
      '#title' => $this->t('Tracker address'),
      '#description' => $this->t('For example <code>https://tracker.example.com</code>. Nothing is sent until it is set.'),
      '#default_value' => $config->get('endpoint'),
    ];
    $form['connection']['key'] = [
      '#type' => 'item',
      '#title' => $this->t('Key'),
      '#markup' => Key::isSet() ? $this->t('Found.') : $this->t('Not set.'),
      '#description' => $this->t('The key is not stored in configuration. Set <code>$settings["tracker_monitoring_key"]</code> in settings.php, or the <code>TRACKER_MONITORING_KEY</code> environment variable.'),
    ];

    $form['reports'] = [
      '#type' => 'details',
      '#title' => $this->t('Reports'),
      '#open' => TRUE,
    ];
    $form['reports']['send_packages'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Packages'),
      '#description' => $this->t('Installed packages, what they require and the project\'s own requirements, plus Drupal core, PHP and database versions.'),
      '#default_value' => $config->get('send_packages'),
    ];
    $form['reports']['send_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Status'),
      '#description' => $this->t('Warnings and errors from the status report, cron age, maintenance mode and pending updates.'),
      '#default_value' => $config->get('send_status'),
    ];
    $form['reports']['send_logs'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Logs'),
      '#description' => $this->t('New rows from the database log (dblog), sent to Tracker every hour; Tracker passes them on to the log server and keeps none. The project in Tracker must have its logs switched on and a site name. Rows from before the first send are not sent. Not needed on sites that ship syslog.'),
      '#default_value' => $config->get('send_logs'),
    ];

    $form['logs'] = [
      '#type' => 'details',
      '#title' => $this->t('Log rows'),
      '#open' => TRUE,
      '#states' => [
        'visible' => [':input[name="send_logs"]' => ['checked' => TRUE]],
      ],
    ];
    $form['logs']['log_min_severity'] = [
      '#type' => 'select',
      '#title' => $this->t('Lowest severity to send'),
      '#options' => array_map('strval', RfcLogLevel::getLevels()),
      '#default_value' => $config->get('log_min_severity'),
    ];
    $form['logs']['log_excluded_types'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Types to leave out'),
      '#description' => $this->t('One type per line, as shown in the log (for example <em>page not found</em>).'),
      '#default_value' => implode("\n", $config->get('log_excluded_types') ?? []),
      '#rows' => 4,
    ];
    $form['logs']['log_send_filled_message'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Send the message with its variables filled in'),
      '#description' => $this->t('By default only the message template is sent. The filled-in message is scrubbed of email addresses, IP addresses and query strings, but may still hold personal data.'),
      '#default_value' => $config->get('log_send_filled_message'),
    ];

    $form['syslog'] = [
      '#type' => 'details',
      '#title' => $this->t('Syslog'),
      '#open' => TRUE,
    ];
    $form['syslog']['set_syslog_format'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Set the syslog format for Tracker'),
      '#description' => $this->t('If the Syslog module is installed, its line format is <code>@format</code>, which is what Tracker\'s log shipper reads. This is applied as a configuration override: nothing is saved in the Syslog module\'s own settings. Turn it off if the site uses syslog for something else.', ['@format' => SyslogFormatOverride::FORMAT]),
      '#default_value' => $config->get('set_syslog_format'),
    ];
    $form['syslog']['status'] = [
      '#type' => 'item',
      '#title' => $this->t('Status'),
      '#markup' => $this->syslogStatus(),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $types = preg_split('/\R/', (string) $form_state->getValue('log_excluded_types'));
    $types = array_values(array_unique(array_filter(array_map('trim', $types), 'strlen')));

    $this->config('tracker_monitoring.settings')
      ->set('endpoint', rtrim(trim((string) $form_state->getValue('endpoint')), '/'))
      ->set('send_packages', (bool) $form_state->getValue('send_packages'))
      ->set('send_status', (bool) $form_state->getValue('send_status'))
      ->set('send_logs', (bool) $form_state->getValue('send_logs'))
      ->set('log_min_severity', (int) $form_state->getValue('log_min_severity'))
      ->set('log_excluded_types', $types)
      ->set('log_send_filled_message', (bool) $form_state->getValue('log_send_filled_message'))
      ->set('set_syslog_format', (bool) $form_state->getValue('set_syslog_format'))
      ->save();

    parent::submitForm($form, $form_state);
  }

  /**
   * Describes the Syslog module and whether the format override is applied.
   */
  private function syslogStatus(): string {
    if (!$this->moduleHandler->moduleExists('syslog')) {
      return (string) $this->t('The Syslog module is not installed. Nothing to set.');
    }

    $syslog = $this->config('syslog.settings');
    $lines = [
      $this->config('tracker_monitoring.settings')->get('set_syslog_format')
        ? $this->t('Installed. The format is set by this module.')
        : $this->t('Installed. The format is not set by this module (currently <code>@format</code>).', ['@format' => $syslog->get('format')]),
    ];
    if ($syslog->get('identity') !== 'drupal') {
      $lines[] = $this->t('Warning: the identity is <code>@identity</code>, but the log shipper expects <code>drupal</code>.', ['@identity' => $syslog->get('identity')]);
    }
    if ((int) $syslog->get('facility') !== LOG_LOCAL0) {
      $lines[] = $this->t('Warning: the facility is not <code>LOG_LOCAL0</code>, which is what the rsyslog configuration reads.');
    }
    return implode('<br>', array_map('strval', $lines));
  }

}
