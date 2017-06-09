<?php

namespace Drupal\imagemagick\Plugin\ImageToolkit;

use Drupal\Component\Render\FormattableMarkup;
use Drupal\Component\Serialization\Exception\InvalidDataTypeException;
use Drupal\Component\Serialization\Yaml;
use Drupal\Component\Utility\Html;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\ImageToolkit\ImageToolkitBase;
use Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface;
use Drupal\Core\Link;
use Drupal\Core\Url;
use Drupal\file_mdm\FileMetadataManagerInterface;
use Drupal\imagemagick\ImagemagickExecArguments;
use Drupal\imagemagick\ImagemagickExecManagerInterface;
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
   * EXIF orientation not fetched.
   */
  const EXIF_ORIENTATION_NOT_FETCHED = -99;

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
   * The file metadata manager service.
   *
   * @var \Drupal\file_mdm\FileMetadataManagerInterface
   */
  protected $fileMetadataManager;

  /**
   * The ImageMagick execution manager service.
   *
   * @var \Drupal\imagemagick\ImagemagickExecManagerInterface
   */
  protected $execManager;

  /**
   * The execution arguments object.
   *
   * @var \Drupal\imagemagick\ImagemagickExecArguments
   */
  protected $arguments;

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
   * The number of frames of the source image, for multi-frame images.
   *
   * @var int
   */
  protected $frames;

  /**
   * Image orientation retrieved from EXIF information.
   *
   * @var int
   */
  protected $exifOrientation;

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
   * @param \Drupal\file_mdm\FileMetadataManagerInterface $file_metadata_manager
   *   The file metadata manager service.
   * @param \Drupal\imagemagick\ImagemagickExecManagerInterface $exec_manager
   *   The ImageMagick execution manager service.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ImageToolkitOperationManagerInterface $operation_manager, LoggerInterface $logger, ConfigFactoryInterface $config_factory, ModuleHandlerInterface $module_handler, ImagemagickFormatMapperInterface $format_mapper, FileMetadataManagerInterface $file_metadata_manager, ImagemagickExecManagerInterface $exec_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $operation_manager, $logger, $config_factory);
    $this->moduleHandler = $module_handler;
    $this->formatMapper = $format_mapper;
    $this->fileMetadataManager = $file_metadata_manager;
    $this->execManager = $exec_manager;
    $this->arguments = new ImagemagickExecArguments($this->execManager);
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
      $container->get('file_metadata_manager'),
      $container->get('imagemagick.exec_manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('imagemagick.settings');

    $form['imagemagick'] = [
      '#markup' => $this->t("<a href=':im-url'>ImageMagick</a> and <a href=':gm-url'>GraphicsMagick</a> are stand-alone packages for image manipulation. At least one of them must be installed on the server, and you need to know where it is located. Consult your server administrator or hosting provider for details.", [
        ':im-url' => 'http://www.imagemagick.org',
        ':gm-url' => 'http://www.graphicsmagick.org',
      ]),
    ];
    $form['quality'] = [
      '#type' => 'number',
      '#title' => $this->t('Image quality'),
      '#size' => 10,
      '#min' => 0,
      '#max' => 100,
      '#maxlength' => 3,
      '#default_value' => $config->get('quality'),
      '#field_suffix' => '%',
      '#description' => $this->t('Define the image quality of processed images. Ranges from 0 to 100. Higher values mean better image quality but bigger files.'),
    ];

    // Settings tabs.
    $form['imagemagick_settings'] = [
      '#type' => 'vertical_tabs',
      '#tree' => FALSE,
    ];

    // Graphics suite to use.
    $form['suite'] = [
      '#type' => 'details',
      '#title' => $this->t('Graphics package'),
      '#group' => 'imagemagick_settings',
    ];
    $options = [
      'imagemagick' => $this->getPackageLabel('imagemagick'),
      'graphicsmagick' => $this->getPackageLabel('graphicsmagick'),
    ];
    $form['suite']['binaries'] = [
      '#type' => 'radios',
      '#title' => $this->t('Suite'),
      '#default_value' => $this->getPackage(),
      '#options' => $options,
      '#required' => TRUE,
      '#description' => $this->t("Select the graphics package to use."),
    ];
    // Path to binaries.
    $form['suite']['path_to_binaries'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Path to the package executables'),
      '#default_value' => $config->get('path_to_binaries'),
      '#required' => FALSE,
      '#description' => $this->t('If needed, the path to the package executables (<kbd>convert</kbd>, <kbd>identify</kbd>, <kbd>gm</kbd>, etc.), <b>including</b> the trailing slash/backslash. For example: <kbd>/usr/bin/</kbd> or <kbd>C:\Program Files\ImageMagick-6.3.4-Q16\</kbd>.'),
    ];
    // Version information.
    $status = $this->execManager->checkPath($config->get('path_to_binaries'));
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
        '@suite' => $this->getPackageLabel(),
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
      $this->addArgument('-list format');
      $output = NULL;
      $this->execManager->execute('convert', $this->arguments, $output);
      $this->resetArguments();
      $formats_info = implode('<br />', explode("\n", preg_replace('/\r/', '', Html::escape($output))));
      $form['formats']['list'] = [
        '#type' => 'details',
        '#collapsible' => TRUE,
        '#open' => FALSE,
        '#title' => $this->t('Format list'),
        '#description' => $this->t("Supported image formats returned by executing <kbd>'convert -list format'</kbd>. <b>Note:</b> these are the formats supported by the installed @suite executable, <b>not</b> by the toolkit.<br /><br />", ['@suite' => $this->getPackageLabel()]),
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
    // Cache metadata.
    $configure_link = Link::fromTextAndUrl(
      $this->t('Configure File Metadata Manager'),
      Url::fromRoute('file_mdm.settings')
    );
    $form['exec']['metadata_caching'] = [
      '#type' => 'item',
      '#title' => $this->t("Cache image metadata"),
      '#description' => $this->t("The File Metadata Manager module allows to cache image metadata. This reduces file I/O and <kbd>shell</kbd> calls. @configure.", [
        '@configure' => $configure_link->toString(),
      ]),
    ];
    // Prepend arguments.
    $form['exec']['prepend'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Prepend arguments'),
      '#default_value' => $config->get('prepend'),
      '#required' => FALSE,
      '#description' => $this->t('Use this to add e.g. <kbd>-limit</kbd> or <kbd>-debug</kbd> arguments in front of the others when executing the <kbd>identify</kbd> and <kbd>convert</kbd> commands.'),
    ];
    // Locale.
    $form['exec']['locale'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Locale'),
      '#default_value' => $config->get('locale'),
      '#required' => FALSE,
      '#description' => $this->t("The locale to be used to prepare the command passed to executables. The default, <kbd>'en_US.UTF-8'</kbd>, should work in most cases. If that is not available on the server, enter another locale. 'Installed Locales' below provides a list of locales installed on the server."),
    ];
    // Installed locales.
    $locales = $this->execManager->getInstalledLocales();
    $locales_info = implode('<br />', explode("\n", preg_replace('/\r/', '', Html::escape($locales))));
    $form['exec']['installed_locales'] = [
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#open' => FALSE,
      '#title' => $this->t('Installed locales'),
      '#description' => $this->t("This is the list of all locales available on this server. It is the output of executing <kbd>'locale -a'</kbd> on the operating system."),
    ];
    $form['exec']['installed_locales']['list'] = [
      '#markup' => "<pre>" . $locales_info . "</pre>",
    ];
    // Log warnings.
    $form['exec']['log_warnings'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Log warnings'),
      '#default_value' => $config->get('log_warnings'),
      '#description' => $this->t('Log a warning entry in the watchdog when the execution of a command returns with a non-zero code, but no error message.'),
    ];
    // Debugging.
    $form['exec']['debug'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Display debugging information'),
      '#default_value' => $config->get('debug'),
      '#description' => $this->t('Shows commands and their output to users with the %permission permission.', [
        '%permission' => $this->t('Administer site configuration'),
      ]),
    ];

    // Advanced image settings.
    $form['advanced'] = [
      '#type' => 'details',
      '#title' => $this->t('Advanced image settings'),
      '#group' => 'imagemagick_settings',
    ];
    $form['advanced']['density'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Change image resolution to 72 ppi'),
      '#default_value' => $config->get('advanced.density'),
      '#return_value' => 72,
      '#description' => $this->t("Resamples the image <a href=':help-url'>density</a> to a resolution of 72 pixels per inch, the default for web images. Does not affect the pixel size or quality.", [
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#density',
      ]),
    ];
    $form['advanced']['colorspace'] = [
      '#type' => 'select',
      '#title' => $this->t('Convert colorspace'),
      '#default_value' => $config->get('advanced.colorspace'),
      '#options' => [
        'RGB' => $this->t('RGB'),
        'sRGB' => $this->t('sRGB'),
        'GRAY' => $this->t('Gray'),
      ],
      '#empty_value' => 0,
      '#empty_option' => $this->t('- Original -'),
      '#description' => $this->t("Converts processed images to the specified <a href=':help-url'>colorspace</a>. The color profile option overrides this setting.", [
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#colorspace',
      ]),
      '#states' => [
        'enabled' => [
          ':input[name="imagemagick[advanced][profile]"]' => ['value' => ''],
        ],
      ],
    ];
    $form['advanced']['profile'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Color profile path'),
      '#default_value' => $config->get('advanced.profile'),
      '#description' => $this->t("The path to a <a href=':help-url'>color profile</a> file that all processed images will be converted to. Leave blank to disable. Use a <a href=':color-url'>sRGB profile</a> to correct the display of professional images and photography.", [
        ':help-url' => 'http://www.imagemagick.org/script/command-line-options.php#profile',
        ':color-url' => 'http://www.color.org/profiles.html',
      ]),
    ];

    return $form;
  }

  /**
   * Gets the binaries package in use.
   *
   * @param string $package
   *   (optional) Force the graphics package.
   *
   * @return string
   *   The default package ('imagemagick'|'graphicsmagick'), or the $package
   *   argument.
   */
  public function getPackage($package = NULL) {
    return $this->execManager->getPackage($package);
  }

  /**
   * Gets a translated label of the binaries package in use.
   *
   * @param string $package
   *   (optional) Force the package.
   *
   * @return string
   *   A translated label of the binaries package in use, or the $package
   *   argument.
   */
  public function getPackageLabel($package = NULL) {
    return $this->execManager->getPackageLabel($package);
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
    return $this->execManager->checkPath($path, $package);
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    try {
      // Check that the format map contains valid YAML.
      $image_formats = Yaml::decode($form_state->getValue([
        'imagemagick', 'formats', 'mapping', 'image_formats',
      ]));
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
      $status = $this->execManager->checkPath($form_state->getValue([
        'imagemagick', 'suite', 'path_to_binaries',
      ]), $form_state->getValue(['imagemagick', 'suite', 'binaries']));
      if ($status['errors']) {
        $form_state->setErrorByName('imagemagick][suite][path_to_binaries', new FormattableMarkup(implode('<br />', $status['errors']), []));
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('imagemagick.settings');
    $config
      ->set('quality', (int) $form_state->getValue([
        'imagemagick', 'quality',
      ]))
      ->set('binaries', (string) $form_state->getValue([
        'imagemagick', 'suite', 'binaries',
      ]))
      ->set('path_to_binaries', (string) $form_state->getValue([
        'imagemagick', 'suite', 'path_to_binaries',
      ]))
      ->set('use_identify', (bool) $form_state->getValue([
        'imagemagick', 'exec', 'use_identify',
      ]))
      ->set('image_formats', Yaml::decode($form_state->getValue([
        'imagemagick', 'formats', 'mapping', 'image_formats',
      ])))
      ->set('prepend', (string) $form_state->getValue([
        'imagemagick', 'exec', 'prepend',
      ]))
      ->set('locale', (string) $form_state->getValue([
        'imagemagick', 'exec', 'locale',
      ]))
      ->set('log_warnings', (bool) $form_state->getValue([
        'imagemagick', 'exec', 'log_warnings',
      ]))
      ->set('debug', (bool) $form_state->getValue([
        'imagemagick', 'exec', 'debug',
      ]))
      ->set('advanced.density', (int) $form_state->getValue([
        'imagemagick', 'advanced', 'density',
      ]))
      ->set('advanced.colorspace', (string) $form_state->getValue([
        'imagemagick', 'advanced', 'colorspace',
      ]))
      ->set('advanced.profile', (string) $form_state->getValue([
        'imagemagick', 'advanced', 'profile',
      ]));
    $config->save();
  }

  /**
   * {@inheritdoc}
   */
  public function isValid() {
    return ((bool) $this->getMimeType());
  }

  /**
   * {@inheritdoc}
   */
  public function setSource($source) {
    parent::setSource($source);
    $this->arguments->setSource($source);
    return $this;
  }

  /**
   * {@inheritdoc}
   */
  public function getSource() {
    return $this->arguments->getSource();
  }

  /**
   * Gets the local filesystem path to the image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getSourceLocalPath() {
    // If sourceLocalPath is NULL, then ensure it is prepared. This can
    // happen if image was identified via cached metadata: the cached data are
    // available, but the temp file path is not resolved, or even the temp file
    // could be missing if it was copied locally from a remote file system.
    if (!$this->arguments->getSourceLocalPath() && $this->getSource()) {
      $this->moduleHandler->alter('imagemagick_pre_parse_file', $this->arguments);
    }
    return $this->arguments->getSourceLocalPath();
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
    $this->arguments->setSourceLocalPath($path);
    return $this;
  }

  /**
   * Gets the source image format.
   *
   * @return string
   *   The source image format.
   */
  public function getSourceFormat() {
    return $this->arguments->getSourceFormat();
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
    $this->arguments->setSourceFormat($this->formatMapper->isFormatEnabled($format) ? $format : '');
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
    $this->arguments->setSourceFormat($format ?: '');
    return $this;
  }

  /**
   * Gets the source EXIF orientation.
   *
   * @return int
   *   The source EXIF orientation.
   */
  public function getExifOrientation() {
    if ($this->exifOrientation === static::EXIF_ORIENTATION_NOT_FETCHED) {
      if ($this->getSource() !== NULL) {
        $file_md = $this->fileMetadataManager->uri($this->getSource());
        if ($file_md->getLocalTempPath() === NULL) {
          $file_md->setLocalTempPath($this->getSourceLocalPath());
        }
        $orientation = $file_md->getMetadata('exif', 'Orientation');
        $this->setExifOrientation(isset($orientation['value']) ? $orientation['value'] : NULL);
      }
      else {
        $this->setExifOrientation(NULL);
      }
    }
    return $this->exifOrientation;
  }

  /**
   * Sets the source EXIF orientation.
   *
   * @param int|null $exif_orientation
   *   The EXIF orientation.
   *
   * @return $this
   */
  public function setExifOrientation($exif_orientation) {
    $this->exifOrientation = $exif_orientation ? (int) $exif_orientation : NULL;
    return $this;
  }

  /**
   * Gets the source image number of frames.
   *
   * @return int
   *   The number of frames of the image.
   */
  public function getFrames() {
    return $this->frames;
  }

  /**
   * Sets the source image number of frames.
   *
   * @param int|null $frames
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
    return $this->arguments->getDestination();
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
    $this->arguments->setDestination($destination);
    return $this;
  }

  /**
   * Gets the local filesystem path to the destination image file.
   *
   * @return string
   *   A filesystem path.
   */
  public function getDestinationLocalPath() {
    return $this->arguments->getDestinationLocalPath();
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
    $this->arguments->setDestinationLocalPath($path);
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
    return $this->arguments->getDestinationFormat();
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
    $this->arguments->setDestinationFormat($this->formatMapper->isFormatEnabled($format) ? $format : '');
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
    $this->arguments->setDestinationFormat($format ?: '');
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
    return $this->arguments->getArguments();
  }

  /**
   * Gets the command line arguments string for the binary.
   *
   * Removes any argument used internally within the toolkit.
   *
   * @return string
   *   The string of command line arguments.
   */
  public function getStringForBinary() {
    return $this->arguments->getStringForBinary();
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
    $this->arguments->addArgument($arg);
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
    $this->arguments->prependArgument($arg);
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
    return $this->arguments->findArgument($arg);
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
    $this->arguments->removeArgument($index);
    return $this;
  }

  /**
   * Resets the command line arguments.
   *
   * @return $this
   */
  public function resetArguments() {
    $this->arguments->resetArguments();
    return $this;
  }

  /**
   * Returns the count of command line arguments.
   *
   * @return $this
   */
  public function countArguments() {
    return $this->arguments->countArguments();
  }

  /**
   * Escapes a string.
   *
   * @param string $arg
   *   The string to escape.
   *
   * @return string
   *   An escaped string for use in the
   *   ImagemagickExecManagerInterface::execute method.
   */
  public function escapeShellArg($arg) {
    return $this->execManager->escapeShellArg($arg);
  }

  /**
   * {@inheritdoc}
   */
  public function save($destination) {
    $this->setDestination($destination);
    if ($ret = $this->convert()) {
      // Allow modules to alter the destination file.
      $this->moduleHandler->alter('imagemagick_post_save', $this->arguments);
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
    $this->moduleHandler->alter('imagemagick_pre_parse_file', $this->arguments);

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
        // image and EXIF orientation, so set to defaults.
        ->setExifOrientation(static::EXIF_ORIENTATION_NOT_FETCHED)
        ->setFrames(NULL);
      return TRUE;
    }

    return FALSE;
  }

  /**
   * Calls the convert executable with the specified arguments.
   *
   * @return bool
   *   TRUE if the file could be converted, FALSE otherwise.
   */
  protected function convert() {
    $config = $this->configFactory->get('imagemagick.settings');

    // Ensure sourceLocalPath is prepared.
    $this->getSourceLocalPath();

    // Allow modules to alter the command line parameters.
    $command = 'convert';
    $this->moduleHandler->alter('imagemagick_arguments', $this->arguments, $command);

    // Delete any cached file metadata for the destination image file, before
    // creating a new one, and release the URI from the manager so that
    // metadata will not stick in the same request.
    $this->fileMetadataManager->deleteCachedMetadata($this->getDestination());
    $this->fileMetadataManager->release($this->getDestination());

    // When destination format differs from source format, and source image
    // is multi-frame, convert only the first frame.
    $destination_format = $this->getDestinationFormat() ?: $this->getSourceFormat();
    if ($this->getSourceFormat() !== $destination_format && ($this->getFrames() === NULL || $this->getFrames() > 1)) {
      $this->arguments->setSourceFrames('[0]');
    }

    // Execute the command.
    $success = $this->execManager->execute($command, $this->arguments) && file_exists($this->getDestinationLocalPath());

    // If successful, parsing was done via identify, single frame image, and
    // both width and height are defined, we can safely build a new
    // FileMetadata entry and assign data to it.
    if ($success && $config->get('use_identify') && $this->getFrames() === 1 && $this->getWidth() !== NULL && $this->getHeight() !== NULL) {
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
        'source_local_path' => $this->getDestinationLocalPath(),
      ];
      $destination_image_md->loadMetadata('imagemagick_identify', $metadata);
    }

    return $success;
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
      $status = $this->execManager->checkPath($this->configFactory->get('imagemagick.settings')->get('path_to_binaries'));
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
