<?php

namespace Drupal\imagemagick;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Psr\Log\LoggerInterface;

/**
 * Manage execution of ImageMagick/GraphicsMagick commands.
 */
class ImagemagickExecManager implements ImagemagickExecManagerInterface {

  use StringTranslationTrait;

  /**
   * Whether we are running on Windows OS.
   *
   * @var bool
   */
  protected $isWindows;

  /**
   * The app root.
   *
   * @var string
   */
  protected $appRoot;

  /**
   * The current user.
   *
   * @var \Drupal\Core\Session\AccountProxyInterface
   */
  protected $currentUser;

  /**
   * The logger service.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * The configuration factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * Constructs an ImagemagickExecManager object.
   *
   * @param \Psr\Log\LoggerInterface $logger
   *   A logger instance.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param string $app_root
   *   The app root.
   * @param \Drupal\Core\Session\AccountProxyInterface $current_user
   *   The current user.
   */
  public function __construct(LoggerInterface $logger, ConfigFactoryInterface $config_factory, $app_root, AccountProxyInterface $current_user) {
    $this->logger = $logger;
    $this->configFactory = $config_factory;
    $this->appRoot = $app_root;
    $this->currentUser = $current_user;
    $this->isWindows = substr(PHP_OS, 0, 3) === 'WIN';
  }

  /**
   * {@inheritdoc}
   */
  public function getPackage($package = NULL) {
    if ($package === NULL) {
      $package = $this->configFactory->get('imagemagick.settings')->get('binaries');
    }
    return $package;
  }

  /**
   * {@inheritdoc}
   */
  public function getPackageLabel($package = NULL) {
    switch ($this->getPackage($package)) {
      case 'imagemagick':
        return $this->t('ImageMagick');

      case 'graphicsmagick':
        return $this->t('GraphicsMagick');

      default:
        return $package;

    }
  }

  /**
   * {@inheritdoc}
   */
  public function checkPath($path, $package = NULL) {
    $status = array(
      'output' => '',
      'errors' => array(),
    );

    // Execute gm or convert based on settings.
    $command = $this->getPackage($package) === 'imagemagick' ? 'convert' : 'gm';

    // If a path is given, we check whether the binary exists and can be
    // invoked.
    if (!empty($path)) {
      $executable = $this->getExecutable($command, $path);

      // Check whether the given file exists.
      if (!is_file($executable)) {
        $status['errors'][] = $this->t('The @suite executable %file does not exist.', array('@suite' => $this->getPackageLabel($package), '%file' => $executable));
      }
      // If it exists, check whether we can execute it.
      elseif (!is_executable($executable)) {
        $status['errors'][] = $this->t('The @suite file %file is not executable.', array('@suite' => $this->getPackageLabel($package), '%file' => $executable));
      }
    }

    // In case of errors, check for open_basedir restrictions.
    if ($status['errors'] && ($open_basedir = ini_get('open_basedir'))) {
      $status['errors'][] = $this->t('The PHP <a href=":php-url">open_basedir</a> security restriction is set to %open-basedir, which may prevent to locate the @suite executable.', array(
        '@suite' => $this->getPackageLabel($package),
        '%open-basedir' => $open_basedir,
        ':php-url' => 'http://php.net/manual/en/ini.core.php#ini.open-basedir',
      ));
    }

    // Unless we had errors so far, try to invoke convert.
    if (!$status['errors']) {
      $error = NULL;
      $arguments = new ImagemagickExecArguments();
      $arguments->addArgument('-version');
      $this->execute($command, $arguments, $status['output'], $error, $path);
      if ($error !== '') {
        $status['errors'][] = $error;
      }
    }

    return $status;
  }

  /**
   * {@inheritdoc}
   */
  public function execute($command, ImagemagickExecArguments $arguments, &$output = NULL, &$error = NULL, $path = NULL) {
    $cmd = $this->getExecutable($command, $path);

    if ($source_path = $arguments->getSourceLocalPath()) {
      if (($source_frames = $arguments->getSourceFrames()) !== NULL) {
        $source_path .= $source_frames;
      }
      $source_path = $this->escapeShellArg($source_path);
    }

    if ($destination_path = $arguments->getDestinationLocalPath()) {
      $destination_path = $this->escapeShellArg($destination_path);
      // If the format of the derivative image has to be changed, concatenate
      // the new image format and the destination path, delimited by a colon.
      // @see http://www.imagemagick.org/script/command-line-processing.php#output
      if (($format = $arguments->getDestinationFormat()) !== '') {
        $destination_path = $format . ':' . $destination_path;
      }
    }

    switch ($command) {
      case 'identify':
        $cmdline = implode(' ', $arguments->getArguments()) . ' ' . $source_path;
        break;

      case 'convert':
        // ImageMagick arguments.
        // Command line format: "convert input [arguments] output".
        // @see http://www.imagemagick.org/Usage/basics/#cmdline
        $cmdline = '';
        if ($source_path) {
          $cmdline .= $source_path . ' ';
        }
        $cmdline .= implode(' ', $arguments->getArguments()) . ' ' . $destination_path;
        break;

      case 'gm':
        // GraphicsMagick arguments.
        // Command line format: "gm convert [arguments] input output".
        // @see http://www.graphicsmagick.org/GraphicsMagick.html
        $cmdline = 'convert ' . implode(' ', $arguments->getArguments()) . ' ' . $source_path . ' ' . $destination_path;
        break;

    }

    $return_code = $this->runOsShell($cmd, $cmdline, $this->getPackage(), $output, $error);

    if ($return_code !== FALSE) {
      // If the executable returned a non-zero code, log to the watchdog.
      if ($return_code != 0) {
        if ($error === '') {
          // If there is no error message, log a warning.
          $this->logger->warning("@suite returned with code @code [command: @command @cmdline]", [
            '@suite' => $this->getPackageLabel(),
            '@code' => $return_code,
            '@command' => $cmd,
            '@cmdline' => $cmdline,
          ]);
        }
        else {
          // Log $error with context information.
          $this->logger->error("@suite error @code: @error [command: @command @cmdline]", [
            '@suite' => $this->getPackageLabel(),
            '@code' => $return_code,
            '@error' => $error,
            '@command' => $cmd,
            '@cmdline' => $cmdline,
          ]);
        }
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
  public function runOsShell($command, $arguments, $id, &$output = NULL, &$error = NULL) {
    if ($this->isWindows) {
      // Use Window's start command with the /B flag to make the process run in
      // the background and avoid a shell command line window from showing up.
      // @see http://us3.php.net/manual/en/function.exec.php#56599
      // Use /D to run the command from PHP's current working directory so the
      // file paths don't have to be absolute.
      $command = 'start "' . $id . '" /D ' . $this->escapeShellArg($this->appRoot) . ' /B ' . $this->escapeShellArg($command);
    }
    $command_line = $command . ' ' . $arguments;

    // Executes the command on the OS via proc_open().
    $descriptors = [
      // This is stdin.
      0 => ['pipe', 'r'],
      // This is stdout.
      1 => ['pipe', 'w'],
      // This is stderr.
      2 => ['pipe', 'w'],
    ];

    if ($h = proc_open($command_line, $descriptors, $pipes, $this->appRoot)) {
      $output = '';
      while (!feof($pipes[1])) {
        $output .= fgets($pipes[1]);
      }
      $output = utf8_encode($output);
      $error = '';
      while (!feof($pipes[2])) {
        $error .= fgets($pipes[2]);
      }
      $error = utf8_encode($error);
      fclose($pipes[0]);
      fclose($pipes[1]);
      fclose($pipes[2]);
      $return_code = proc_close($h);
    }
    else {
      $return_code = FALSE;
    }

    // Display debugging information to authorized users.
    if ($this->configFactory->get('imagemagick.settings')->get('debug') && $this->currentUser->hasPermission('administer site configuration')) {
      debug($command_line, $this->t('@suite command', ['@suite' => $this->getPackageLabel($id)]), TRUE);
      if ($output !== '') {
        debug($output, $this->t('@suite output', ['@suite' => $this->getPackageLabel($id)]), TRUE);
      }
      if ($error !== '') {
        debug($error, $this->t('@suite error @return_code', ['@suite' => $this->getPackageLabel($id), '@return_code' => $return_code]), TRUE);
      }
    }

    return $return_code;
  }

  /**
   * Gets the list of locales installed on the server.
   *
   * @return string
   *   The string resulting from the execution of 'locale -a' in *nix systems.
   */
  public function getInstalledLocales() {
    $output = '';
    if ($this->isWindows === FALSE) {
      $this->runOsShell('locale', '-a', 'locale', $output);
    }
    else {
      $output = (string) $this->t("List not available on Windows servers.");
    }
    return $output;
  }

  /**
   * Returns the full path to the executable.
   *
   * @param string $command
   *   The program to execute, typically 'convert', 'identify' or 'gm'.
   * @param string $path
   *   (optional) A custom path to the folder of the executable. When left
   *   empty, the setting imagemagick.settings.path_to_binaries is taken.
   *
   * @return string
   *   The full path to the executable.
   */
  protected function getExecutable($command, $path = NULL) {
    // $path is only passed from the validation of the image toolkit form, on
    // which the path to convert is configured. @see ::checkPath()
    if (!isset($path)) {
      $path = $this->configFactory->get('imagemagick.settings')->get('path_to_binaries');
    }

    $executable = $command;
    if ($this->isWindows) {
      $executable .= '.exe';
    }

    return $path . $executable;
  }

  /**
   * Escapes a string.
   *
   * PHP escapeshellarg() drops non-ascii characters, this is a replacement.
   *
   * Stop-gap replacement until core issue #1561214 has been solved. Solution
   * proposed in #1502924-8.
   *
   * PHP escapeshellarg() on Windows also drops % (percentage sign) characters.
   * We prevent this by replacing it with a pattern that should be highly
   * unlikely to appear in the string itself and does not contain any
   * "dangerous" character at all (very wide definition of dangerous). After
   * escaping we replace that pattern back with a % character.
   *
   * @param string $arg
   *   The string to escape.
   *
   * @return string
   *   An escaped string for use in the ::execute method.
   */
  public function escapeShellArg($arg) {
    static $percentage_sign_replace_pattern = '1357902468IMAGEMAGICKPERCENTSIGNPATTERN8642097531';

    // Put the configured locale in a static to avoid multiple config get calls
    // in the same request.
    static $config_locale;

    if (!isset($config_locale)) {
      $config_locale = $this->configFactory->get('imagemagick.settings')->get('locale');
      if (empty($config_locale)) {
        $config_locale = FALSE;
      }
    }

    if ($this->isWindows) {
      // Temporarily replace % characters.
      $arg = str_replace('%', $percentage_sign_replace_pattern, $arg);
    }

    // If no locale specified in config, return with standard.
    if ($config_locale === FALSE) {
      $arg_escaped = escapeshellarg($arg);
    }
    else {
      // Get the current locale.
      $current_locale = setlocale(LC_CTYPE, 0);
      if ($current_locale != $config_locale) {
        // Temporarily swap the current locale with the configured one.
        setlocale(LC_CTYPE, $config_locale);
        $arg_escaped = escapeshellarg($arg);
        setlocale(LC_CTYPE, $current_locale);
      }
      else {
        $arg_escaped = escapeshellarg($arg);
      }
    }

    // Get our % characters back.
    if ($this->isWindows) {
      $arg_escaped = str_replace($percentage_sign_replace_pattern, '%', $arg_escaped);
    }

    return $arg_escaped;
  }

}
