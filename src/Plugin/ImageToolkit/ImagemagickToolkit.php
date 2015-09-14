<?php

/**
 * @file
 * Contains \Drupal\imagemagick\Plugin\ImageToolkit\ImagemagickToolkit.
 */

namespace Drupal\imagemagick\Plugin\ImageToolkit;

use Drupal\Component\Utility\Image as ImageUtility;
use Drupal\Component\Utility\SafeMarkup;
use Drupal\Component\Utility\Unicode;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\ImageToolkit\ImageToolkitBase;
use Drupal\Core\ImageToolkit\ImageToolkitOperationManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface;

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
   * The MIME type guessing service.
   * @todo change if extension mapping service gets in, see #2311679
   *
   * @var \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface
   */
  protected $mimeTypeGuesser;

  /**
   * The module handler service.
   *
   * @var \Drupal\Core\Extension\ModuleHandlerInterface
   */
  protected $moduleHandler;

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
   * The source image EXIF orientation.
   *
   * @var string
   */
  protected $exifOrientation = NULL;

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
   * @param \Symfony\Component\HttpFoundation\File\MimeType\MimeTypeGuesserInterface $mime_type_guesser
   *   The MIME type guessing service.
   * @param \Drupal\Core\Extension\ModuleHandlerInterface $module_handler
   *   The module handler service.
   */
  public function __construct(array $configuration, $plugin_id, array $plugin_definition, ImageToolkitOperationManagerInterface $operation_manager, LoggerInterface $logger, ConfigFactoryInterface $config_factory, MimeTypeGuesserInterface $mime_type_guesser, ModuleHandlerInterface $module_handler) {
    parent::__construct($configuration, $plugin_id, $plugin_definition, $operation_manager, $logger, $config_factory);
    // @todo change if extension mapping service gets in, see #2311679
    $this->mimeTypeGuesser = $mime_type_guesser;
    $this->moduleHandler = $module_handler;
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
      $container->get('file.mime_type.guesser.extension'),
      $container->get('module_handler')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function buildConfigurationForm(array $form, FormStateInterface $form_state) {
    $config = $this->configFactory->getEditable('imagemagick.settings');

    $form['imagemagick'] = array(
      '#type' => 'item',
      '#description' => $this->t('ImageMagick is a stand-alone program for image manipulation. It must be installed on the server and you need to know where it is located. Consult your server administrator or hosting provider for details.'),
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
    $form['gm'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Enable <a href="@gm-url">GraphicsMagick</a> support', array(
        '@gm-url' => 'http://www.graphicsmagick.org',
      )),
      '#default_value' => $config->get('gm'),
    );
    $form['path_to_binaries'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Path to the ImageMagick binaries'),
      '#default_value' => $config->get('path_to_binaries'),
      '#required' => FALSE,
      '#description' => $this->t('If needed, the path to the ImageMagick <kbd>convert</kbd> and <kbd>identify</kbd> binaries. For example: <kbd>/usr/bin/</kbd> or <kbd>C:\Program Files\ImageMagick-6.3.4-Q16\</kbd>.'),
    );
    $form['prepend'] = array(
      '#type' => 'textfield',
      '#title' => $this->t('Prepend arguments'),
      '#default_value' => $config->get('prepend'),
      '#required' => FALSE,
      '#description' => $this->t('Additional arguments to add in front of the others when executing the <kbd>convert</kbd> command. Useful if you need to set <kbd>-limit</kbd> arguments.'),
    );

    $form['use_identify'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Use "identify"'),
      '#default_value' => $config->get('use_identify'),
      '#description' => $this->t('Use ImageMagick <kbd>identify</kbd> binary to parse image files to determine image format and dimensions. If not selected, the PHP <kbd>getimagesize</kbd> function will be used.'),
    );

    $form['debug'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Display debugging information'),
      '#default_value' => $config->get('debug'),
      '#description' => $this->t('Shows ImageMagick commands and their output to users with the %permission permission.', array(
        '%permission' => $this->t('Administer site configuration'),
      )),
    );

    $form['advanced'] = array(
      '#type' => 'details',
      '#collapsible' => TRUE,
      '#collapsed' => TRUE,
      '#title' => $this->t('Advanced settings'),
    );
    $form['advanced']['density'] = array(
      '#type' => 'checkbox',
      '#title' => $this->t('Change image resolution to 72 ppi'),
      '#default_value' => $config->get('advanced.density'),
      '#return_value' => 72,
      '#description' => $this->t('Resamples the image <a href="@help-url">density</a> to a resolution of 72 pixels per inch, the default for web images. Does not affect the pixel size or quality.', array(
        '@help-url' => 'http://www.imagemagick.org/script/command-line-options.php#density',
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
      '#description' => $this->t('Converts processed images to the specified <a href="@help-url">colorspace</a>. The color profile option overrides this setting.', array(
        '@help-url' => 'http://www.imagemagick.org/script/command-line-options.php#colorspace',
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
      '#description' => $this->t('The path to a <a href="@help-url">color profile</a> file that all processed images will be converted to. Leave blank to disable. Use a <a href="@color-url">sRGB profile</a> to correct the display of professional images and photography.', array(
        '@help-url' => 'http://www.imagemagick.org/script/command-line-options.php#profile',
        '@color-url' => 'http://www.color.org/profiles.html',
      )),
    );

    return $form;
  }

  /**
   * Verifies file path of ImageMagick convert binary by checking its version.
   *
   * @param string $path
   *   The user-submitted file path to the convert binary.
   *
   * @return array
   *   An associative array containing:
   *   - output: The shell output of 'convert -version', if any.
   *   - errors: A list of error messages indicating whether ImageMagick could
   *     not be found or executed.
   */
  public function checkPath($path) {
    $status = array(
      'output' => '',
      'errors' => array(),
    );

    // Execute gm or convert based on settings.
    $command = $this->configFactory->get('imagemagick.settings')->get('gm') ? 'gm' : 'convert';
    $path .= $command;

    // If a path is given, we check whether the binary exists and can be
    // invoked.
    if ($path != 'convert' && $path != 'gm') {
      // Check whether the given file exists.
      if (!is_file($path)) {
        $status['errors'][] = $this->t('The specified ImageMagick binary %file does not exist.', array('%file' => $path));
      }
      // If it exists, check whether we can execute it.
      elseif (!is_executable($path)) {
        $status['errors'][] = $this->t('The specified ImageMagick binary %file is not executable.', array('%file' => $path));
      }
    }

    // In case of errors, check for open_basedir restrictions.
    if ($status['errors'] && ($open_basedir = ini_get('open_basedir'))) {
      $status['errors'][] = $this->t('The PHP <a href="@php-url">open_basedir</a> security restriction is set to %open-basedir, which may prevent to locate ImageMagick.', array(
        '%open-basedir' => $open_basedir,
        '@php-url' => 'http://php.net/manual/en/ini.core.php#ini.open-basedir',
      ));
    }

    // Unless we had errors so far, try to invoke convert.
    if (!$status['errors']) {
      $error = NULL;
      $this->addArgument('-version');
      $this->imagemagickExec($command, $status['output'], $error, $path);
      if ($error !== '') {
        // $error normally needs check_plain(), but file system errors on
        // Windows use a unknown encoding. check_plain() would eliminate the
        // entire string.
        $status['errors'][] = $error;
      }
      $this->resetArguments();
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function validateConfigurationForm(array &$form, FormStateInterface $form_state) {
    $status = $this->checkPath($form_state->getValue(['imagemagick', 'path_to_binaries']));
    if ($status['errors']) {
      $form_state->setErrorByName('imagemagick][path_to_binaries');
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitConfigurationForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory->getEditable('imagemagick.settings')
      ->set('quality', $form_state->getValue(array('imagemagick', 'quality')))
      ->set('gm', $form_state->getValue(array('imagemagick', 'gm')))
      ->set('path_to_binaries', $form_state->getValue(array('imagemagick', 'path_to_binaries')))
      ->set('prepend', $form_state->getValue(array('imagemagick', 'prepend')))
      ->set('use_identify', $form_state->getValue(array('imagemagick', 'use_identify')))
      ->set('debug', $form_state->getValue(array('imagemagick', 'debug')))
      ->set('advanced.density', $form_state->getValue(array('imagemagick', 'advanced', 'density')))
      ->set('advanced.colorspace', $form_state->getValue(array('imagemagick', 'advanced', 'colorspace')))
      ->set('advanced.profile', $form_state->getValue(array('imagemagick', 'advanced', 'profile')))
      ->save();
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
    $this->sourceFormat = $format;
    return $this;
  }

  /**
   * Gets the source EXIF orientation.
   *
   * @return integer
   *   The source EXIF orientation.
   */
  public function getExifOrientation() {
    return $this->exifOrientation;
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
    $this->exifOrientation = (int) $exif_orientation;
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
   * When set, it is passed to ImageMagick's convert binary in the syntax
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
   * When set, it is passed to ImageMagick's convert binary in the syntax
   * "[format]:[destination]", where [format] is a string denoting an
   * ImageMagick's image format.
   *
   * @param string $destination_format
   *   The image destination format.
   *
   * @return $this
   */
  public function setDestinationFormat($destination_format) {
    $this->destinationFormat = $destination_format;
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
    // @todo change if extension mapping service gets in, see #2311679
    $format = $this->getSourceFormat();
    return empty($format) ? NULL : $this->mimeTypeGuesser->guess('dummy.' . $format);
  }

  /**
   * Gets the command line arguments for the Imagemagick binary.
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
   * Stop-gap replacement while core issue #1561214 is solved.
   *
   * @return string
   *   An escaped string for use in the ::imagemagickExec method.
   */
  public function escapeShellArg($arg) {
    // Solution proposed in #1502924-8.
    $old_locale = setlocale(LC_CTYPE, 0);
    setlocale(LC_CTYPE, 'en_US.UTF-8');
    $arg_escaped = escapeshellarg($arg);
    setlocale(LC_CTYPE, $old_locale);
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
    // Allow modules to alter the source file.
    $this->moduleHandler->alter('imagemagick_pre_parse_file', $this);
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
    $this->addArgument('-format ' . $this->escapeShellArg('format:%m|width:%w|height:%h|exif_orientation:%[EXIF:Orientation]'));
    if ($identify_output = $this->identify()) {
      $identify_output = explode('|', $identify_output);
      $data = [];
      foreach ($identify_output as $item) {
        list($key, $value) = explode(':', $item);
        $data[$key] = $value;
      }
      $format = isset($data['format']) ? Unicode::strtolower($data['format']) : NULL;
      if ($format && in_array($format, static::getSupportedExtensions())) {
        $this
          ->setSourceFormat($format)
          ->setWidth($data['width'])
          ->setHeight($data['height'])
          ->setExifOrientation($data['exif_orientation']);
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Parses the image file using the PHP getimagesize() function.
   *
   * @return bool
   *   TRUE if the file could be found and is an image, FALSE otherwise.
   */
  protected function parseFileViaGetImageSize() {
    $data = @getimagesize($this->getSourceLocalPath());
    if ($data && in_array(image_type_to_extension($data[2], FALSE), static::getSupportedExtensions())) {
      $this
        ->setSourceFormat(image_type_to_extension($data[2], FALSE))
        ->setWidth($data[0])
        ->setHeight($data[1]);
      return TRUE;
    }
    return FALSE;
  }

  /**
   * Calls the identify executable on the specified file.
   *
   * @return bool
   *   TRUE if the file could be identified, FALSE otherwise.
   */
  protected function identify() {
    // Allow modules to alter the ImageMagick command line parameters.
    $command = 'identify';
    $this->moduleHandler->alter('imagemagick_arguments', $this, $command);

    // Execute the 'identify' command.
    $output = NULL;
    $ret = $this->imagemagickExec($command, $output);
    $this->resetArguments();
    return ($ret === TRUE) ? $output : FALSE;
  }

  /**
   * Calls the convert executable with the specified arguments.
   *
   * @return bool
   *   TRUE if the file could be converted, FALSE otherwise.
   */
  protected function convert() {
    // Allow modules to alter the ImageMagick command line parameters.
    $command = $this->configFactory->get('imagemagick.settings')->get('gm') ? 'gm' : 'convert';
    $this->moduleHandler->alter('imagemagick_arguments', $this, $command);

    // If the format of the derivative image was changed, concatenate the new
    // image format and the destination path, delimited by a colon.
    // @see http://www.imagemagick.org/script/command-line-processing.php#output
    // @see hook_imagemagick_arguments_alter()
    if (($format = $this->getDestinationFormat()) !== '') {
      $this->setDestinationLocalPath($format . ':' . $this->getDestinationLocalPath());
    }

    return $this->imagemagickExec($command) ? file_exists($this->getDestinationLocalPath()) : FALSE;
  }

  /**
   * Executes the ImageMagick convert executable as shell command.
   *
   * @param string $command
   *   The ImageMagick executable to run.
   * @param string $command_args
   *   A string containing arguments to pass to the command, which must have
   *   been passed through $this->escapeShellArg() already.
   * @param string &$output
   *   (optional) A variable to assign the shell stdout to, passed by reference.
   * @param string &$error
   *   (optional) A variable to assign the shell stderr to, passed by reference.
   * @param string $path
   *   (optional) A custom file path to the executable binary.
   *
   * @return mixed
   *   The return value depends on the shell command result:
   *   - Boolean TRUE if the command succeeded.
   *   - Boolean FALSE if the shell process could not be executed.
   *   - Error exit status code integer returned by the executable.
   */
  protected function imagemagickExec($command, &$output = NULL, &$error = NULL, $path = NULL) {
    // $path is only passed from the validation of the image toolkit form, on
    // which the path to convert is configured.
    // @see ::checkPath()
    if (!isset($path)) {
      $path = $this->configFactory->get('imagemagick.settings')->get('path_to_binaries') . $command;
    }

    // Use Drupal's root as working directory to resolve relative paths
    // correctly.
    $drupal_path = DRUPAL_ROOT;

    if (strstr($_SERVER['SERVER_SOFTWARE'], 'Win32') || strstr($_SERVER['SERVER_SOFTWARE'], 'IIS')) {
      // Use Window's start command with the /B flag to make the process run in
      // the background and avoid a shell command line window from showing up.
      // @see http://us3.php.net/manual/en/function.exec.php#56599
      // Use /D to run the command from PHP's current working directory so the
      // file paths don't have to be absolute.
      $path = 'start "ImageMagick" /D ' . $this->escapeShellArg($drupal_path) . ' /B ' . $this->escapeShellArg($path);
    }

    if ($source_path = $this->getSourceLocalPath()) {
      $source_path = $this->escapeShellArg($source_path);
    }
    if ($destination_path = $this->getDestinationLocalPath()) {
      $destination_path = $this->escapeShellArg($destination_path);
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
    if ($h = proc_open($cmdline, $descriptors, $pipes, $drupal_path)) {
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
        $current_user = \Drupal::currentUser();
        if ($current_user->hasPermission('administer site configuration')) {
          debug($cmdline, $this->t('ImageMagick command'), TRUE);
          if ($output !== '') {
            debug($output, $this->t('ImageMagick output'), TRUE);
          }
          if ($error !== '') {
            debug($error, $this->t('ImageMagick error'), TRUE);
          }
        }
      }

      // If ImageMagick returned a non-zero code, log to the watchdog.
      if ($return_code != 0) {
        // If there is no error message, clarify this.
        if ($error === '') {
          $error = $this->t('No error message.');
        }
        // Format $error with as full message, passed by reference.
        $error = $this->t('ImageMagick error @code: !error', array(
          '@code' => $return_code,
          '!error' => $error,
        ));
        $this->logger->error($error);
        // ImageMagick exited with an error code, return it.
        return $return_code;
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
    $path = $this->configFactory->get('imagemagick.settings')->get('path_to_binaries');
    $requirements = array();
    $status = $this->checkPath($path);
    if (!empty($status['errors'])) {
      foreach ($status['errors'] as $id => $error) {
        $requirements['imagemagick_path_' . $id] = array(
          'title' => $this->t('ImageMagick'),
          'value' => $this->t('Path error'),
          'description' => $error,
          'severity' => REQUIREMENT_ERROR,
        );
      }
    }
    else {
      $output = preg_replace('/\n/', '<br/>', $status['output']);
      $requirements['imagemagick_version'] = array(
        'title' => $this->t('ImageMagick'),
        'description' => SafeMarkup::format($output),
        'severity' => REQUIREMENT_INFO,
      );
    }
    return $requirements;
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
    return array('png', 'jpeg', 'jpg', 'gif', 'svg');
  }

}
