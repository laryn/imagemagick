<?php

namespace Drupal\imagemagick\Plugin\ImageToolkit;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Cache\Cache;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\ImageToolkit\ImageToolkitBase;
use Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Url;
use Drupal\file_mdm\FileMetadataManagerInterface;
use Drupal\imagemagick\ImagemagickFormatMapperInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides ImageMagick integration toolkit for image manipulation.
 *
 * @ImageToolkit(
 *   id = "imagemagick",
 *   title = @Translation("ImageMagick image toolkit")
 * )
 */
class ImagemagickToolkit extends ImageToolkitBase {

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

  /**
   * The format mapper service.
   *
   * @var \Drupal\imagemagick\ImagemagickFormatMapperInterface
   */
  protected $formatMapper;

  /**
   * The app root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The file metadata manager service.
   *
   * @var \Drupal\file_mdm\FileMetadataManagerInterface
   */
  protected $fileMetadataManager;

  /**
   * The date formatter service.
   *
   * @var \Drupal\Core\Datetime\DateFormatterInterface
   */
  protected $dateFormatter;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The array of command line arguments to be used by 'convert'.
   *
   * @var string[]
   */
  protected $arguments = array();

  /**
   * The width of the image.
   *
   * @var int
   */
  protected $width;

  /**
   * The height of the image.
   *
   * @var int
   */
  protected $height;

  /**
   * The number of frames of the image, for multi-frame images (e.g. GIF).
   *
   * @var int
   */
  protected $frames;

  /**
   * The local filesystem path to the source image file.
   *
   * @var string
   */
  protected $sourceLocalPath = '';

  /**
   * The source image format.
   *
   * @var string
   */
  protected $sourceFormat = '';

  /**
   * Keeps a copy of source image EXIF information.
   *
   * @var array
   */
  protected $exifInfo = [];

  /**
   * The image destination URI/path on saving.
   *
   * @var string
   */
  protected $destination = NULL;

  /**
   * The local filesystem path to the image destination.
   *
   * @var string
   */
  protected $destinationLocalPath = '';

  /**
   * The image destination format on saving.
   *
   * @var string
   */
  protected $destinationFormat = '';

  /**
   * Constructs an ImagemagickToolkit object.
   *
   * @param array $configuration
   *   A configuration array containing information about the plugin instance.
   * @param string $plugin_id
   *   The plugin_id for the plugin instance.
   * @param array $plugin_definition
   *   The plugin implementation definition.
   * @param \Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface $operation_manager
   *   The toolkit operation manager.
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   * @param \Drupal\imagemagick\ImagemagickFormatMapperInterface $format_mapper
   *   The format mapper service.
   * @param string $app_root
   *   The app root.
   * @param \Drupal\file_mdm\FileMetadataManagerInterface $file_metadata_manager
   *   The file metadata manager service.
   * @param \Drupal\Core\Datetime\DateFormatterInterface $date_formatter
   *   The date formatter service.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ImageToolkitOperationManagerInterface $operation_manager, LoggerInterface $logger, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, ImagemagickFormatMapperInterface $format_mapper, $app_root, FileMetadataManagerInterface $file_metadata_manager, DateFormatterInterface $date_formatter, AccountProxyInterface $current_user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $operation_manager, $logger, $config_factory);
    $this->moduleHandler = $module_handler;
    $this->formatMapper = $format_mapper;
    $this->appRoot = $app_root;
    $this->fileMetadataManager = $file_metadata_manager;
    $this->dateFormatter = $date_formatter;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('image.toolkit.operation.manager'),
      $container->get('logger.channel.image'),
      $container->get('config.factory'),
      $container->get('module_handler'),
      $container->get('imagemagick.format_mapper'),
      $container->get('app.root'),
      $container->get('file_metadata_manager'),
      $container->get('date.formatter'),
      $container->get('current_user')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('imagemagick.settings');
    $package = $this->configFactory->get('imagemagick.settings')->get('binaries');
    $suite = $package === 'imagemagick' ? $this->t('ImageMagick') : $this->t('GraphicsMagick');

    $form['imagemagick'] = array(
      '#markup' => $this->t("<a href=':im-url'>ImageMagick</a> and <a href=':gm-url'>GraphicsMagick</a> are stand-alone packages for image manipulation. At least one of them must be installed on the server, and you need to know where it is located. Consult your server administrator or hosting provider for details.", [
        ':im-url' => 'http://www.imagemagick.org',
        ':gm-url' => 'http://www.graphicsmagick.org',
      ]),
    );
    $form['quality'] = array(
      '#type' => 'number',
      '#title' => $this->t('Image quality'),
      '#size' => 10,
      '#min' => 0,
      '#max' => 100,
      '#maxlength' => 3,
      '#default_value' => $config->get('quality'),
      '#field_suffix' => '%',
      '#description' => $this->t('Define the image quality of processed images. Ranges from 0 to 100. Higher values mean better image quality but bigger files.'),
    );

    // Settings tabs.
    $form['imagemagick_settings'] = array(
      '#type' => 'vertical_tabs',
      '#tree' => FALSE,
    );

    // Graphics suite to use.
    $form['suite'] = array(
      '#type' => 'details',
      '#title' => $this->t('Graphics package'),
      '#group' => 'imagemagick_settings',
    );
    $options = [
      'imagemagick' => $this->t("ImageMagick"),
      'graphicsmagick' => $this->t("GraphicsMagick"),
    ];
    $form['suite']['binaries'] = [
      '#type' => 'radios',
      '#title' => $this->t('Suite'),
      '#default_value' => $config->get('binaries'),
      '#options' => $options,
      '#required' => TRUE,
      '#description' => $this->t("Select the graphics package to use."),
    ];
    // Path to binaries.
    $form['suite']['path_to_binaries'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Path to the package executables'),
      '#default_value' => $config->get('path_to_binaries'),
      '#required' => FALSE,
      '#description' => $this->t('If needed, the path to the package executables (<kbd>convert</kbd>, <kbd>identify</kbd>, <kbd>gm</kbd>, etc.), <b>including</b> the trailing slash/backslash. For example: <kbd>/usr/bin/</kbd> or <kbd>C:\Program Files\ImageMagick-6.3.4-Q16\</kbd>.'),
    );
    // Version information.
    $status = $this->checkPath($config->get('path_to_binaries'));
    if (empty($status['errors'])) {
      $version_info = explode("\n", preg_replace('/\r/', '', Html::escape($status['output'])));
    }
    else {
      $version_info = $status['errors'];
    }
    $form['suite']['version'] = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#open' => TRUE,
      '#title' => $this->t('Version information'),
      '#description' => '<pre>' . implode('<br />', $version_info) . '</pre>',
    ];

    // Image formats.
    $form['formats'] = [
      '#type' => 'details',
      '#title' => $this->t('Image formats'),
      '#group' => 'imagemagick_settings',
    ];
    // Image formats enabled in the toolkit.
    $form['formats']['enabled'] = [
      '#type' => 'item',
      '#title' => $this->t('Currently enabled images'),
      '#description' => $this->t("@suite formats: %formats<br />Image file extensions: %extensions", [
        '%formats' => implode(', ', $this->formatMapper->getEnabledFormats()),
        '%extensions' => Unicode::strtolower(implode(', ', static::getSupportedExtensions())),
        '@suite' => $suite,
      ]),
    ];
    // Image formats map.
    $form['formats']['mapping'] = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#open' => TRUE,
      '#title' => $this->t('Enable/disable image formats'),
      '#description' => $this->t("Edit the map below to enable/disable image formats. Enabled image file extensions will be determined by the enabled formats, through their MIME types. More information in the module's README.txt"),
    ];
    $form['formats']['mapping']['image_formats'] = [
      '#type' => 'textarea',
      '#rows' => 15,
      '#default_value' => Yaml::encode($config->get('image_formats')),
    ];
    // Image formats supported by the package.
    if (empty($status['errors'])) {
      $command = $package === 'imagemagick' ? 'convert' : 'gm';
      $this->addArgument('-list format');
      $this->imagemagickExec($command, $output);
      $this->resetArguments();
      $formats_info = implode('<br />', explode("\n", preg_replace('/\r/', '', Html::escape($output))));
      $form['formats']['list'] = [
        '#type' => 'details',
        '#collapsible' => TRUE,
        '#open' => FALSE,
        '#title' => $this->t('Format list'),
        '#description' => $this->t("Supported image formats returned by executing <kbd>'convert -list format'</kbd>. <b>Note:</b> these are the formats supported by the installed @suite executable, <b>not</b> by the toolkit.<br /><br />", ['@suite' => $suite]),
      ];
      $form['formats']['list']['list'] = [
        '#markup' => "<pre>" . $formats_info . "</pre>",
      ];
    }

    // Execution options.
    $form['exec'] = [
      '#type' => 'details',
      '#title' => $this->t('Execution options'),
      '#group' => 'imagemagick_settings',
    ];

    // Use 'identify' command.
    $form['exec']['use_identify'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Use "identify"'),
      '#default_value' => $config->get('use_identify'),
      '#description' => $this->t('Use the <kbd>identify</kbd> command to parse image files to determine image format and dimensions. If not selected, the PHP <kbd>getimagesize</kbd> function will be used, BUT this will limit the image formats supported by the toolkit.'),
    ];
    // Cache identify metadata.
    $form['exec']['identify_cache'] = [
      '#type' => 'details',
      '#open' => TRUE,
      '#collapsible' => FALSE,
      '#title' => $this->t('Identify caching'),
      '#states' => [
        'visible' => [
          ':input[name="imagemagick[exec][use_identify]"]' => ['checked' => TRUE],
        ],
      ],
    ];
    $form['exec']['identify_cache']['enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache "identify" metadata'),
      '#default_value' => $config->get('parse_caching.enabled'),
      '#description' => $this->t("If selected, results of the <kbd>identify</kbd> command will be cached. This will reduce file I/O and <kbd>shell</kbd> calls."),
    ];
    $options = [86400, 172800, 604800, 1209600, 3024000, 7862400];
    $options = array_map([$this->dateFormatter, 'formatInterval'], array_combine($options, $options));
    $options = [-1 => $this->t('Never')] + $options;
    $form['exec']['identify_cache']['expiration'] = [
      '#type' => 'select',
      '#title' => $this->t('Cache expires'),
      '#default_value' => $config->get('parse_caching.expiration'),
      '#options' => $options,
      '#description' => $this->t("Specify the required lifetime of cached entries. Longer times may lead to increased cache sizes."),
    ];
    $form['exec']['identify_cache']['disallowed_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Excluded paths'),
      '#rows' => 3,
      '#default_value' => implode("\n", $config->get('parse_caching.disallowed_paths')),
      '#description' => $this->t("Only files prefixed by a valid URI scheme will be cached, like for example <kbd>public://</kbd>. Files in the <kbd>temporary://</kbd> scheme will never be cached. Specify here if there are any paths to be additionally <strong>excluded</strong> from caching, one per line. Use wildcard patterns when entering the path. For example, <kbd>public://styles/*</kbd>."),
    ];
    // Prepend arguments.
    $form['exec']['prepend'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Prepend arguments'),
      '#default_value' => $config->get('prepend'),
      '#required' => FALSE,
      '#description' => $this->t('Additional arguments to add in front of the others when executing commands. Useful if you need to set e.g. <kbd>-limit</kbd> or <kbd>-debug</kbd> arguments.'),
    );
    // Locale.
    $form['exec']['locale'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Locale'),
      '#default_value' => $config->get('locale'),
      '#required' => FALSE,
      '#description' => $this->t("The locale to be used to prepare the command passed to executables. The default, <kbd>'en_US.UTF-8'</kbd>, should work in most cases. If that is not available on the server, enter another locale. On *nix servers, type <kbd>'locale -a'</kbd> in a shell window to see a list of all locales available."),
    );
    // Debugging.
    $form['exec']['debug'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Display debugging information'),
      '#default_value' => $config->get('debug'),
      '#description' => $this->t('Shows commands and their output to users with the %permission permission.', array(
        '%permission' => $this->t('Administer site configuration'),
      )),
    );

    // Advanced image settings.
    $form['advanced'] = array(
      '#type' => 'details',
      '#title' => $this->t('Advanced image settings'),
      '#group' => 'imagemagick_settings',
    );
    $form['advanced']['density'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Change image resolution to 72 ppi'),
      '#default_value' => $config->get('advanced.density'),
      '#return_value' => 72,
      '#description' => $this->t("Resamples the image <a href=':help-url'>density</a> to a resolution of 72 pixels per inch, the default for web images. Does not affect the pixel size or quality.", array(
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#density',
      )),
    );
    $form['advanced']['colorspace'] = array(
      '#type' => 'select',
      '#title' => $this->t('Convert colorspace'),
      '#default_value' => $config->get('advanced.colorspace'),
      '#options' => array(
        'RGB' => $this->t('RGB'),
        'sRGB' => $this->t('sRGB'),
        'GRAY' => $this->t('Gray'),
      ),
      '#empty_value' => 0,
      '#empty_option' => $this->t('- Original -'),
      '#description' => $this->t("Converts processed images to the specified <a href=':help-url'>colorspace</a>. The color profile option overrides this setting.", array(
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#colorspace',
      )),
      '#states' => array(
        'enabled' => array(
          ':input[name="imagemagick[advanced][profile]"]' => array('value' => ''),
        ),
      ),
    );
    $form['advanced']['profile'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Color profile path'),
      '#default_value' => $config->get('advanced.profile'),
      '#description' => $this->t("The path to a <a href=':help-url'>color profile</a> file that all processed images will be converted to. Leave blank to disable. Use a <a href=':color-url'>sRGB profile</a> to correct the display of professional images and photography.", array(
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#profile',
        ':color-url' => 'http://www.color.org/profiles.html',
      )),
    );

    return $form;
  }

  /**
   * Verifies file path of the executable binary by checking its version.
   *
   * @param string $path
   *   The user-submitted file path to the convert binary.
   * @param string $package
   *   (optional) The graphics package to use.
   *
   * @return array
   *   An associative array containing:
   *   - output: The shell output of 'convert -version', if any.
   *   - errors: A list of error messages indicating if the executable could
   *     not be found or executed.
   */
  public function checkPath($path, $package = NULL) {
    $status = array(
      'output' => '',
      'errors' => array(),
    );

    // Execute gm or convert based on settings.
    $package = $package ?: $this->configFactory->get('imagemagick.settings')->get('binaries');
    $suite = $package === 'imagemagick' ? $this->t('ImageMagick') : $this->t('GraphicsMagick');
    $command = $package === 'imagemagick' ? 'convert' : 'gm';
    $path .= $command;

    // If a path is given, we check whether the binary exists and can be
    // invoked.
    if ($path != 'convert' && $path != 'gm') {
      // Check whether the given file exists.
      if (!is_file($path)) {
        $status['errors'][] = $this->t('The @suite executable %file does not exist.', array('@suite' => $suite, '%file' => $path));
      }
      // If it exists, check whether we can execute it.
      elseif (!is_executable($path)) {
        $status['errors'][] = $this->t('The @suite file %file is not executable.', array('@suite' => $suite, '%file' => $path));
      }
    }

    // In case of errors, check for open_basedir restrictions.
    if ($status['errors'] && ($open_basedir = ini_get('open_basedir'))) {
      $status['errors'][] = $this->t('The PHP <a href=":php-url">open_basedir</a> security restriction is set to %open-basedir, which may prevent to locate the @suite executable.', array(
        '@suite' => $suite,
        '%open-basedir' => $open_basedir,
        ':php-url' => 'http://php.net/manual/en/ini.core.php#ini.open-basedir',
      ));
    }

    // Unless we had errors so far, try to invoke convert.
    if (!$status['errors']) {
      $error = NULL;
      $this->addArgument('-version');
      $this->imagemagickExec($command, $status['output'], $error, $path);
      $this->resetArguments();
      if ($error !== '') {
        // $error normally needs check_plain(), but file system errors on
        // Windows use a unknown encoding. check_plain() would eliminate the
        // entire string.
        $status['errors'][] = $error;
      }
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    try {
      // Check that the format map contains valid YAML.
      $image_formats = Yaml::decode($form_state->getValue(['imagemagick', 'formats', 'mapping', 'image_formats']));
      // Validate the enabled image formats.
      $errors = $this->formatMapper->validateMap($image_formats);
      if ($errors) {
        $form_state->setErrorByName('imagemagick][formats][mapping][image_formats', new FormattableMarkup("<pre>@errors</pre>", ['@errors' => Yaml::encode($errors)]));
      }
    }
    catch (InvalidDataTypeException $e) {
      // Invalid YAML detected, show details.
      $form_state->setErrorByName('imagemagick][formats][mapping][image_formats', $this->t("YAML syntax error: @error", ['@error' => $e->getMessage()]));
    }
    // Validate the binaries path only if this toolkit is selected, otherwise
    // it will prevent the entire image toolkit selection form from being
    // submitted.
    if ($form_state->getValue(['image_toolkit']) === 'imagemagick') {
      $status = $this->checkPath($form_state->getValue(['imagemagick', 'suite', 'path_to_binaries']), $form_state->getValue(['imagemagick', 'suite', 'binaries']));
      if ($status['errors']) {
        $form_state->setErrorByName('imagemagick][suite][path_to_binaries', new FormattableMarkup(implode('<br />', $status['errors']), []));
      }
    }
    // Validate cache exclusion paths.
    if (!empty($disallowed_paths = $form_state->getValue(['imagemagick', 'exec', 'identify_cache', 'disallowed_paths']))) {
      $disallowed_paths = preg_replace('/\r/', '', $disallowed_paths);
      $paths = explode("\n", $disallowed_paths);
      foreach ($paths as $path) {
        if (!file_valid_uri($path)) {
          $form_state->setErrorByName('imagemagick][exec][identify_cache][disallowed_paths', $this->t("'@path' is an invalid URI path", ['@path' => $path]));
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('imagemagick.settings');
    $config
      ->set('quality', (int) $form_state->getValue(['imagemagick', 'quality']))
      ->set('binaries', (string) $form_state->getValue(['imagemagick', 'suite', 'binaries']))
      ->set('path_to_binaries', (string) $form_state->getValue(['imagemagick', 'suite', 'path_to_binaries']))
      ->set('use_identify', (bool) $form_state->getValue(['imagemagick', 'exec', 'use_identify']))
      ->set('image_formats', Yaml::decode($form_state->getValue(['imagemagick', 'formats', 'mapping', 'image_formats'])))
      ->set('prepend', (string) $form_state->getValue(['imagemagick', 'exec', 'prepend']))
      ->set('locale', (string) $form_state->getValue(['imagemagick', 'exec', 'locale']))
      ->set('debug', (bool) $form_state->getValue(['imagemagick', 'exec', 'debug']))
      ->set('advanced.density', (int) $form_state->getValue(['imagemagick', 'advanced', 'density']))
      ->set('advanced.colorspace', (string) $form_state->getValue(['imagemagick', 'advanced', 'colorspace']))
      ->set('advanced.profile', (string) $form_state->getValue(['imagemagick', 'advanced', 'profile']));

    // Cache related, should invalidate cache if there are changes in settings.
    $needs_cache_invalidation = FALSE;

    $enabled = (bool) $form_state->getValue(['imagemagick', 'exec', 'identify_cache', 'enabled']);
    if ($enabled != $config->get('parse_caching.enabled')) {
      $config->set('parse_caching.enabled', $enabled);
      $needs_cache_invalidation = TRUE;
    }
    $expiration = (int) $form_state->getValue(['imagemagick', 'exec', 'identify_cache', 'expiration']);
    if ($expiration != $config->get('parse_caching.expiration')) {
      $config->set('parse_caching.expiration', $expiration);
      $needs_cache_invalidation = TRUE;
    }
    $disallowed_paths = (string) $form_state->getValue(['imagemagick', 'exec', 'identify_cache', 'disallowed_paths']);
    if (!empty($disallowed_paths)) {
      $disallowed_paths = explode("\n", preg_replace('/\r/', '', $disallowed_paths));
    }
    else {
      $disallowed_paths = [];
    }
    if ($disallowed_paths != $config->get('parse_caching.disallowed_paths')) {
      $config->set('parse_caching.disallowed_paths', $disallowed_paths);
      $needs_cache_invalidation = TRUE;
    }
    if ($needs_cache_invalidation) {
      Cache::InvalidateTags(['file_mdm:imagemagick_identify']);
    }

    $config->save();
  }

  /**
   * {@inheritdoc}
   */
  public function isValid() {
    return ((bool) $this->getMimeType());
  }

  /**
   * Gets the local filesystem path to the image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getSourceLocalPath() {
    return $this->sourceLocalPath;
  }

  /**
   * Sets the local filesystem path to the image file.
   *
   * @param string $path
   *   A filesystem path.
   *
   * @return $this
   */
  public function setSourceLocalPath($path) {
    $this->sourceLocalPath = $path;
    return $this;
  }

  /**
   * Gets the source image format.
   *
   * @return string
   *   The source image format.
   */
  public function getSourceFormat() {
    return $this->sourceFormat;
  }

  /**
   * Sets the source image format.
   *
   * @param string $format
   *   The image format.
   *
   * @return $this
   */
  public function setSourceFormat($format) {
    $this->sourceFormat = $this->formatMapper->isFormatEnabled($format) ? $format : '';
    return $this;
  }

  /**
   * Sets the source image format from an image file extension.
   *
   * @param string $extension
   *   The image file extension.
   *
   * @return $this
   */
  public function setSourceFormatFromExtension($extension) {
    $format = $this->formatMapper->getFormatFromExtension($extension);
    $this->sourceFormat = $format ?: '';
    return $this;
  }

  /**
   * Gets the source EXIF orientation.
   *
   * @return integer
   *   The source EXIF orientation.
   */
  public function getExifOrientation() {
    if (empty($this->exifInfo)) {
      $this->parseExifData();
    }
    return isset($this->exifInfo['Orientation']) ? $this->exifInfo['Orientation'] : NULL;
  }

  /**
   * Sets the source EXIF orientation.
   *
   * @param integer|null $exif_orientation
   *   The EXIF orientation.
   *
   * @return $this
   */
  public function setExifOrientation($exif_orientation) {
    if (!$exif_orientation) {
      return $this;
    }
    $this->exifInfo['Orientation'] = (int) $exif_orientation !== 0 ? (int) $exif_orientation : NULL;
    return $this;
  }

  /**
   * Gets the source image number of frames.
   *
   * @return integer
   *   The number of frames of the image.
   */
  public function getFrames() {
    return $this->frames;
  }

  /**
   * Sets the source image number of frames.
   *
   * @param integer|null $frames
   *   The number of frames of the image.
   *
   * @return $this
   */
  public function setFrames($frames) {
    $this->frames = $frames;
    return $this;
  }

  /**
   * Gets the image destination URI/path on saving.
   *
   * @return string
   *   The image destination URI/path.
   */
  public function getDestination() {
    return $this->destination;
  }

  /**
   * Sets the image destination URI/path on saving.
   *
   * @param string $destination
   *   The image destination URI/path.
   *
   * @return $this
   */
  public function setDestination($destination) {
    $this->destination = $destination;
    return $this;
  }

  /**
   * Gets the local filesystem path to the destination image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getDestinationLocalPath() {
    return $this->destinationLocalPath;
  }

  /**
   * Sets the local filesystem path to the destination image file.
   *
   * @param string $path
   *   A filesystem path.
   *
   * @return $this
   */
  public function setDestinationLocalPath($path) {
    $this->destinationLocalPath = $path;
    return $this;
  }

  /**
   * Gets the image destination format.
   *
   * When set, it is passed to the convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @return string
   *   The image destination format.
   */
  public function getDestinationFormat() {
    return $this->destinationFormat;
  }

  /**
   * Sets the image destination format.
   *
   * When set, it is passed to the convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @param string $format
   *   The image destination format.
   *
   * @return $this
   */
  public function setDestinationFormat($format) {
    $this->destinationFormat = $this->formatMapper->isFormatEnabled($format) ? $format : '';
    return $this;
  }

  /**
   * Sets the image destination format from an image file extension.
   *
   * When set, it is passed to the convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @param string $extension
   *   The destination image file extension.
   *
   * @return $this
   */
  public function setDestinationFormatFromExtension($extension) {
    $format = $this->formatMapper->getFormatFromExtension($extension);
    $this->destinationFormat = $format ?: '';
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getWidth() {
    return $this->width;
  }

  /**
   * Sets image width.
   *
   * @param int $width
   *   The image width.
   *
   * @return $this
   */
  public function setWidth($width) {
    $this->width = $width;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getHeight() {
    return $this->height;
  }

  /**
   * Sets image height.
   *
   * @param int $height
   *   The image height.
   *
   * @return $this
   */
  public function setHeight($height) {
    $this->height = $height;
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getMimeType() {
    return $this->formatMapper->getMimeTypeFromFormat($this->getSourceFormat());
  }

  /**
   * Gets the command line arguments for the binary.
   *
   * @return string[]
   *   The array of command line arguments.
   */
  public function getArguments() {
    return $this->arguments ?: array();
  }

  /**
   * Adds a command line argument.
   *
   * @param string $arg
   *   The command line argument to be added.
   *
   * @return $this
   */
  public function addArgument($arg) {
    $this->arguments[] = $arg;
    return $this;
  }

  /**
   * Prepends a command line argument.
   *
   * @param string $arg
   *   The command line argument to be prepended.
   *
   * @return $this
   */
  public function prependArgument($arg) {
    array_unshift($this->arguments, $arg);
    return $this;
  }

  /**
   * Finds if a command line argument exists.
   *
   * @param string $arg
   *   The command line argument to be found.
   *
   * @return bool
   *   Returns the array key for the argument if it is found in the array,
   *   FALSE otherwise.
   */
  public function findArgument($arg) {
    foreach ($this->getArguments() as $i => $a) {
      if (strpos($a, $arg) === 0) {
        return $i;
      }
    }
    return FALSE;
  }

  /**
   * Removes a command line argument.
   *
   * @param int $index
   *   The index of the command line argument to be removed.
   *
   * @return $this
   */
  public function removeArgument($index) {
    if (isset($this->arguments[$index])) {
      unset($this->arguments[$index]);
    }
    return $this;
  }

  /**
   * Resets the command line arguments.
   *
   * @return $this
   */
  public function resetArguments() {
    $this->arguments = array();
    return $this;
  }

  /**
   * Returns the count of command line arguments.
   *
   * @return $this
   */
  public function countArguments() {
    return count($this->arguments);
  }

  /**
   * Escapes a string.
   *
   * PHP escapeshellarg() drops non-ascii characters, this is a replacement.
   *
   * Stop-gap replacement while core issue #1561214 is solved. Solution
   * proposed in #1502924-8.
   *
   * @return string
   *   An escaped string for use in the ::imagemagickExec method.
   */
  public function escapeShellArg($arg) {
    // Put the configured locale in a static to avoid multiple config get calls
    // in the same request.
    static $config_locale;
    if (!isset($config_locale)) {
      $config_locale = $this->configFactory->get('imagemagick.settings')->get('locale');
      if (empty($config_locale)) {
        $config_locale = FALSE;
      }
    }

    // If no locale specified in config, return with standard.
    if ($config_locale === FALSE) {
      return escapeshellarg($arg);
    }

    // Get the current locale.
    $current_locale = setlocale(LC_CTYPE, 0);
    // Swap the current locale with the config one, and back, to execute
    // escapeshellarg().
    if ($current_locale != $config_locale) {
      setlocale(LC_CTYPE, $config_locale);
      $arg_escaped = escapeshellarg($arg);
      setlocale(LC_CTYPE, $current_locale);
    }
    else {
      $arg_escaped = escapeshellarg($arg);
    }
    return $arg_escaped;
  }

  /**
   * {@inheritdoc}
   */
  public function save($destination) {
    $this->setDestination($destination);
    if ($ret = $this->convert()) {
      // Allow modules to alter the destination file.
      $this->moduleHandler->alter('imagemagick_post_save', $this);
      // Reset local path to allow saving to other file.
      $this->setDestinationLocalPath('');
    }
    return $ret;
  }

  /**
   * {@inheritdoc}
   */
  public function parseFile() {
    if ($this->configFactory->get('imagemagick.settings')->get('use_identify')) {
      return $this->parseFileViaIdentify();
    }
    else {
      return $this->parseFileViaGetImageSize();
    }
  }

  /**
   * Parses the image file using the 'identify' executable.
   *
   * @return bool
   *   TRUE if the file could be found and is an image, FALSE otherwise.
   */
  protected function parseFileViaIdentify() {
    $config = $this->configFactory->get('imagemagick.settings');

    // Get 'imagemagick_identify' metadata for this image. The file metadata
    // plugin will fetch it from the file via the ::identify() method if data
    // is not already available.
    $file_md = $this->fileMetadataManager->uri($this->getSource());
    $data = $file_md->getMetadata('imagemagick_identify');

    // No data, return.
    if (!$data) {
      return FALSE;
    }

    // Sets the local file path to the one retrieved by identify if available.
    if ($source_local_path = $file_md->getMetadata('imagemagick_identify', 'source_local_path')) {
      $this->setSourceLocalPath($source_local_path);
    }

    // Cache metadata, if required.
    if ($this->isUriFileMetadataCacheable($this->getSource())) {
      $file_md->removeMetadata('imagemagick_identify', 'source_local_path');
      $expiration = $config->get('parse_caching.expiration');
      if ($expiration === -1) {
        $file_md->saveMetadataToCache('imagemagick_identify', ['file_mdm:imagemagick_identify'], Cache::PERMANENT);
      }
      else {
        $file_md->saveMetadataToCache('imagemagick_identify', ['file_mdm:imagemagick_identify'], time() + $expiration);
      }
    }

    // Process parsed data from the first frame.
    $format = $file_md->getMetadata('imagemagick_identify', 'format');
    if ($this->formatMapper->isFormatEnabled($format)) {
      $this
        ->setSourceFormat($format)
        ->setWidth((int) $file_md->getMetadata('imagemagick_identify', 'width'))
        ->setHeight((int) $file_md->getMetadata('imagemagick_identify', 'height'))
        ->setExifOrientation($file_md->getMetadata('imagemagick_identify', 'exif_orientation'))
        ->setFrames($file_md->getMetadata('imagemagick_identify', 'frames_count'));
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Parses the image file using the file metadata 'getimagesize' plugin.
   *
   * @return bool
   *   TRUE if the file could be found and is an image, FALSE otherwise.
   */
  protected function parseFileViaGetImageSize() {
    // Allow modules to alter the source file.
    $this->moduleHandler->alter('imagemagick_pre_parse_file', $this);

    // Get 'getimagesize' metadata for this image.
    $file_md = $this->fileMetadataManager->uri($this->getSource());
    $data = $file_md->getMetadata('getimagesize');

    // No data, return.
    if (!$data) {
      return FALSE;
    }

    // Process parsed data.
    $format = $this->formatMapper->getFormatFromExtension(image_type_to_extension($data[2], FALSE));
    if ($format) {
      $this
        ->setSourceFormat($format)
        ->setWidth($data[0])
        ->setHeight($data[1])
        // 'getimagesize' cannot provide information on number of frames in an
        // image and EXIF orientation, so set to NULL as a default.
        ->setExifOrientation(NULL)
        ->setFrames(NULL);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Checks if image file metadata is cacheable.
   *
   * @param string $uri
   *   The URI of the image file.
   *
   * @return bool
   *   TRUE if file metadata is cacheable based on settings, FALSE otherwise.
   */
  protected function isUriFileMetadataCacheable($uri) {
    $config = $this->configFactory->get('imagemagick.settings');
    // Only cache results of 'identify' and if caching is enabled.
    if (!$config->get('use_identify') || !$config->get('parse_caching.enabled')) {
      return FALSE;
    }
    // URIs without valid scheme, and temporary:// URIs are not cached.
    if (!file_valid_uri($uri) || file_uri_scheme($uri) === 'temporary') {
      return FALSE;
    }
    // URIs falling into disallowed paths are not cached.
    foreach ($config->get('parse_caching.disallowed_paths') as $pattern) {
      if (fnmatch($pattern, $uri)) {
        return FALSE;
      }
    }
    return TRUE;
  }

  /**
   * Parses the image file EXIF data using the PHP read_exif_data() function.
   *
   * @return $this
   */
  protected function parseExifData() {
    $continue = TRUE;
    // Test to see if EXIF is supported by the image format.
    $mime_type = $this->getMimeType();
    if (!in_array($mime_type, ['image/jpeg', 'image/tiff'])) {
      // Not an EXIF enabled image.
      $continue = FALSE;
    }
    $local_path = $this->getSourceLocalPath();
    if ($continue && empty($local_path)) {
      // No file path available. Most likely a new image from scratch.
      $continue = FALSE;
    }
    if ($continue && !function_exists('exif_read_data')) {
      // No PHP EXIF extension enabled, return.
      $this->logger->error('The PHP EXIF extension is not installed. The \'imagemagick\' toolkit is unable to automatically determine image orientation.');
      $continue = FALSE;
    }
    if ($continue && ($exif_data = @exif_read_data($this->getSourceLocalPath()))) {
      $this->exifInfo = $exif_data;
      return $this;
    }
    $this->setExifOrientation(NULL);
    return $this;
  }

  /**
   * Calls the identify executable on the specified file.
   *
   * Note that this method is called by the FileMetadata plugin
   * 'imagemagick_identify', *not* by the toolkit directly.
   *
   * @return array
   *   The array with identify metadata, if the file was parsed correctly.
   *   NULL otherwise.
   */
  public function identify() {
    // Add -format argument.
    $this->addArgument('-format ' . $this->escapeShellArg("format:%m|width:%w|height:%h|exif_orientation:%[EXIF:Orientation]\n"));

    // Allow modules to alter source file and the command line parameters.
    $command = 'identify';
    $this->moduleHandler->alter('imagemagick_pre_parse_file', $this);
    $this->moduleHandler->alter('imagemagick_arguments', $this, $command);

    // Execute the 'identify' command.
    $output = NULL;
    $ret = $this->imagemagickExec($command, $output);
    $this->resetArguments();

    // Process results.
    $data = [];
    if ($ret) {
      // Builds the frames info.
      $frames = [];
      $frames_tmp = explode("\n", $output);
      // Remove empty items at the end of the array.
      while (empty($frames_tmp[count($frames_tmp) - 1])) {
        array_pop($frames_tmp);
      }
      foreach ($frames_tmp as $i => $frame) {
        $info = explode('|', $frame);
        foreach ($info as $item) {
          list($key, $value) = explode(':', $item);
          $frames[$i][$key] = $value;
        }
      }
      $data['frames'] = $frames;
      // Adds the local file path that was resolved via
      // hook_imagemagick_pre_parse_file implementations.
      $data['source_local_path'] = $this->getSourceLocalPath();
    }

    return ($ret === TRUE) ? $data : NULL;
  }

  /**
   * Calls the convert executable with the specified arguments.
   *
   * @return bool
   *   TRUE if the file could be converted, FALSE otherwise.
   */
  protected function convert() {
    $config = $this->configFactory->get('imagemagick.settings');

    // If sourceLocalPath is NULL, then ensure it is prepared. This can
    // happen if image was identified via cached metadata: the cached data are
    // available, but the temp file path is not resolved, or even the temp file
    // could be missing if it was copied locally from a remote file system.
    if (!$this->getSourceLocalPath()) {
      $this->moduleHandler->alter('imagemagick_pre_parse_file', $this);
    }

    // Allow modules to alter the command line parameters.
    $command = $config->get('binaries') === 'imagemagick' ? 'convert' : 'gm';
    $this->moduleHandler->alter('imagemagick_arguments', $this, $command);

    // Delete any cached file metadata for the image file, before creating
    // a new one, and release the URI from the manager so that metadata will
    // not stick in the same request.
    if ($config->get('parse_caching.enabled')) {
      $this->fileMetadataManager->deleteCachedMetadata($this->getDestination());
    }
    $this->fileMetadataManager->release($this->getDestination());

    // Execute the 'convert' or 'gm' command.
    $success = $this->imagemagickExec($command) && file_exists($this->getDestinationLocalPath());

    // If successful, parsing was done via identify, and single frame image,
    // we can safely build a new FileMetadata entry and assign data to it.
    if ($success && $config->get('use_identify') && $this->getFrames() === 1) {
      $destination_image_md = $this->fileMetadataManager->uri($this->getDestination());
      $metadata = [
        'frames' => [
          0 => [
            'format' => $this->getDestinationFormat() ?: $this->getSourceFormat(),
            'width' => $this->getWidth(),
            'height' => $this->getHeight(),
            'exif_orientation' => $this->getExifOrientation(),
          ],
        ],
      ];
      $destination_image_md->loadMetadata('imagemagick_identify', $metadata);
      if ($this->isUriFileMetadataCacheable($this->getDestination())) {
        $expiration = $config->get('parse_caching.expiration');
        if ($expiration === -1) {
          $destination_image_md->saveMetadataToCache('imagemagick_identify', ['file_mdm:imagemagick_identify'], Cache::PERMANENT);
        }
        else {
          $destination_image_md->saveMetadataToCache('imagemagick_identify', ['file_mdm:imagemagick_identify'], time() + $expiration);
        }
      }
      $destination_image_md->setMetadata('imagemagick_identify', 'source_local_path', $this->getDestinationLocalPath());
    }

    return $success;
  }

  /**
   * Executes the convert executable as shell command.
   *
   * @param string $command
   *   The executable to run.
   * @param string &$output
   *   (optional) A variable to assign the shell stdout to, passed by
   *   reference.
   * @param string &$error
   *   (optional) A variable to assign the shell stderr to, passed by
   *   reference.
   * @param string $path
   *   (optional) A custom file path to the executable binary.
   *
   * @return bool
   *   TRUE if the command succeeded, FALSE otherwise. The error exit status
   *   code integer returned by the executable is logged.
   */
  protected function imagemagickExec($command, &$output = NULL, &$error = NULL, $path = NULL) {
    $suite = $this->configFactory->get('imagemagick.settings')->get('binaries') === 'imagemagick' ? 'ImageMagick' : 'GraphicsMagick';

    // $path is only passed from the validation of the image toolkit form, on
    // which the path to convert is configured.
    // @see ::checkPath()
    if (!isset($path)) {
      $path = $this->configFactory->get('imagemagick.settings')->get('path_to_binaries') . $command;
    }

    if (substr(PHP_OS, 0, 3) == 'WIN') {
      // Use Window's start command with the /B flag to make the process run in
      // the background and avoid a shell command line window from showing up.
      // @see http://us3.php.net/manual/en/function.exec.php#56599
      // Use /D to run the command from PHP's current working directory so the
      // file paths don't have to be absolute.
      $path = 'start "' . $suite . '" /D ' . $this->escapeShellArg($this->appRoot) . ' /B ' . $this->escapeShellArg($path);
    }

    if ($source_path = $this->getSourceLocalPath()) {
      // When destination format differs from source format, and source image
      // is multi-frame, convert only the first frame.
      $destination_format = $this->getDestinationFormat() ?: $this->getSourceFormat();
      if ($this->getSourceFormat() !== $destination_format && ($this->getFrames() === NULL || $this->getFrames() > 1)) {
        $source_path .= '[0]';
      }
      $source_path = $this->escapeShellArg($source_path);
    }
    if ($destination_path = $this->getDestinationLocalPath()) {
      $destination_path = $this->escapeShellArg($destination_path);
      // If the format of the derivative image has to be changed, concatenate
      // the new image format and the destination path, delimited by a colon.
      // @see http://www.imagemagick.org/script/command-line-processing.php#output
      if (($format = $this->getDestinationFormat()) !== '') {
        $destination_path = $format . ':' . $destination_path;
      }
    }

    switch($command) {
      case 'identify':
        $cmdline = $path . ' ' . implode(' ', $this->getArguments()) . ' ' . $source_path;
        break;

      case 'convert':
        // ImageMagick arguments:
        // convert input [arguments] output
        // @see http://www.imagemagick.org/Usage/basics/#cmdline
        $cmdline = $path . ' ' . $source_path . ' ' . implode(' ', $this->getArguments()) . ' ' . $destination_path;
        break;

      case 'gm':
        // GraphicsMagick arguments:
        // gm convert [arguments] input output
        // @see http://www.graphicsmagick.org/GraphicsMagick.html
        $cmdline = $path . ' convert ' . implode(' ', $this->getArguments()) . ' '  . $source_path . ' ' . $destination_path;
        break;

    }

    $descriptors = array(
      // stdin
      0 => array('pipe', 'r'),
      // stdout
      1 => array('pipe', 'w'),
      // stderr
      2 => array('pipe', 'w'),
    );
    if ($h = proc_open($cmdline, $descriptors, $pipes, $this->appRoot)) {
      $output = '';
      while (!feof($pipes[1])) {
        $output .= fgets($pipes[1]);
      }
      $error = '';
      while (!feof($pipes[2])) {
        $error .= fgets($pipes[2]);
      }

      fclose($pipes[0]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $return_code = proc_close($h);

      // Display debugging information to authorized users.
      if ($this->configFactory->get('imagemagick.settings')->get('debug')) {
        if ($this->currentUser->hasPermission('administer site configuration')) {
          debug($cmdline, $this->t('@suite command', ['@suite' => $suite]), TRUE);
          if ($output !== '') {
            debug($output, $this->t('@suite output', ['@suite' => $suite]), TRUE);
          }
          if ($error !== '') {
            debug($error, $this->t('@suite error @return_code', ['@suite' => $suite, '@return_code' => $return_code]), TRUE);
          }
        }
      }

      // If the executable returned a non-zero code, log to the watchdog.
      if ($return_code != 0) {
        // If there is no error message, clarify this.
        if ($error === '') {
          $error = $this->t('No error message.');
        }
        // Format $error with as full message, passed by reference.
        $error = $this->t('@suite error @code: @error', array(
          '@suite' => $suite,
          '@code' => $return_code,
          '@error' => $error,
        ));
        $this->logger->error($error);
        // Executable exited with an error code, return FALSE.
        return FALSE;
      }

      // The shell command was executed successfully.
      return TRUE;
    }
    // The shell command could not be executed.
    return FALSE;
  }

  /**
   * {@inheritdoc}
   */
  public function getRequirements() {
    $reported_info = [];
    if (stripos(ini_get('disable_functions'), 'proc_open') !== FALSE) {
      // proc_open() is disabled.
      $severity = REQUIREMENT_ERROR;
      $reported_info[] = $this->t("The <a href=':proc_open_url'>proc_open()</a> PHP function is disabled. It must be enabled for the toolkit to work. Edit the <a href=':disable_functions_url'>disable_functions</a> entry in your php.ini file, or consult your hosting provider.", [
        ':proc_open_url' => 'http://php.net/manual/en/function.proc-open.php',
        ':disable_functions_url' => 'http://php.net/manual/en/ini.core.php#ini.disable-functions',
      ]);
    }
    else {
      $status = $this->checkPath($this->configFactory->get('imagemagick.settings')->get('path_to_binaries'));
      if (!empty($status['errors'])) {
        // Can not execute 'convert'.
        $severity = REQUIREMENT_ERROR;
        foreach ($status['errors'] as $error) {
          $reported_info[] = $error;
        }
        $reported_info[] = $this->t('Go to the <a href=":url">Image toolkit</a> page to configure the toolkit.', [':url' => Url::fromRoute('system.image_toolkit_settings')->toString()]);
      }
      else {
        // No errors, report the version information.
        $severity = REQUIREMENT_INFO;
        $version_info = explode("\n", preg_replace('/\r/', '', Html::escape($status['output'])));
        $more_info_available = FALSE;
        foreach ($version_info as $key => $item) {
          if (stripos($item, 'feature') !== FALSE || $key > 4) {
            $more_info_available = TRUE;
            break;

          }
          $reported_info[] = $item;
        }
        if ($more_info_available) {
          $reported_info[] = $this->t('To display more information, go to the <a href=":url">Image toolkit</a> page, and expand the \'Version information\' section.', [':url' => Url::fromRoute('system.image_toolkit_settings')->toString()]);
        }
        $reported_info[] = '';
        $reported_info[] = $this->t("Enabled image file extensions: %extensions", [
          '%extensions' => Unicode::strtolower(implode(', ', static::getSupportedExtensions())),
        ]);
      }
    }
    return [
      'imagemagick' => [
        'title' => $this->t('ImageMagick'),
        'description' => [
          '#markup' => implode('<br />', $reported_info),
        ],
        'severity' => $severity,
      ],
    ];
  }

  /**
   * {@inheritdoc}
   */
  public static function isAvailable() {
    return TRUE;
  }

  /**
   * {@inheritdoc}
   */
  public static function getSupportedExtensions() {
    return \Drupal::service('imagemagick.format_mapper')->getEnabledExtensions();
  }

}
